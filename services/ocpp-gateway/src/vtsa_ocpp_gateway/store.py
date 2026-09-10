from __future__ import annotations

import asyncio
import json
import math
import time
from collections import defaultdict
from collections.abc import Awaitable
from typing import Any, Protocol, cast

from redis.asyncio import Redis

from vtsa_ocpp_gateway.ids import new_ulid
from vtsa_ocpp_gateway.models import AuthorizationDecision, JsonObject


class GatewayStore(Protocol):
    async def ping(self) -> bool: ...

    async def claim_connection(
        self, charge_point_identity: str, node_id: str, connection_id: str, ttl_seconds: int
    ) -> str | None: ...

    async def renew_connection(
        self, charge_point_identity: str, connection_id: str, ttl_seconds: int
    ) -> bool: ...

    async def release_connection(self, charge_point_identity: str, connection_id: str) -> bool: ...

    async def claim_message(
        self, charge_point_identity: str, unique_id: str, ttl_seconds: int
    ) -> bool: ...

    async def cached_response(self, charge_point_identity: str, unique_id: str) -> str | None: ...

    async def cache_response(
        self, charge_point_identity: str, unique_id: str, response: str, ttl_seconds: int
    ) -> None: ...

    async def publish(self, stream: str, event: JsonObject, max_length: int) -> None: ...

    async def next_protocol_transaction_id(self, charge_point_identity: str) -> int: ...

    async def authorize(
        self, stream: str, request: JsonObject, timeout_seconds: float, max_length: int
    ) -> AuthorizationDecision: ...

    async def close(self) -> None: ...


class MemoryGatewayStore:
    def __init__(self, authorization_decision: AuthorizationDecision | None = None) -> None:
        self.authorization_decision = authorization_decision or AuthorizationDecision("Invalid")
        self.connections: dict[str, tuple[str, str, float]] = {}
        self.message_claims: dict[tuple[str, str], float] = {}
        self.responses: dict[tuple[str, str], tuple[str, float]] = {}
        self.streams: dict[str, list[JsonObject]] = defaultdict(list)
        self.transaction_sequences: dict[str, int] = defaultdict(int)
        self._lock = asyncio.Lock()

    async def ping(self) -> bool:
        return True

    async def claim_connection(
        self, charge_point_identity: str, node_id: str, connection_id: str, ttl_seconds: int
    ) -> str | None:
        async with self._lock:
            previous = self.connections.get(charge_point_identity)
            self.connections[charge_point_identity] = (
                node_id,
                connection_id,
                time.monotonic() + ttl_seconds,
            )
            return None if previous is None or previous[2] <= time.monotonic() else previous[1]

    async def renew_connection(
        self, charge_point_identity: str, connection_id: str, ttl_seconds: int
    ) -> bool:
        async with self._lock:
            current = self.connections.get(charge_point_identity)
            if current is None or current[1] != connection_id:
                return False
            self.connections[charge_point_identity] = (
                current[0],
                connection_id,
                time.monotonic() + ttl_seconds,
            )
            return True

    async def release_connection(self, charge_point_identity: str, connection_id: str) -> bool:
        async with self._lock:
            current = self.connections.get(charge_point_identity)
            if current is None or current[1] != connection_id:
                return False
            del self.connections[charge_point_identity]
            return True

    async def claim_message(
        self, charge_point_identity: str, unique_id: str, ttl_seconds: int
    ) -> bool:
        key = (charge_point_identity, unique_id)
        async with self._lock:
            now = time.monotonic()
            expires_at = self.message_claims.get(key)
            if expires_at is not None and expires_at > now:
                return False
            self.message_claims[key] = now + ttl_seconds
            return True

    async def cached_response(self, charge_point_identity: str, unique_id: str) -> str | None:
        key = (charge_point_identity, unique_id)
        async with self._lock:
            cached = self.responses.get(key)
            if cached is None or cached[1] <= time.monotonic():
                self.responses.pop(key, None)
                return None
            return cached[0]

    async def cache_response(
        self, charge_point_identity: str, unique_id: str, response: str, ttl_seconds: int
    ) -> None:
        async with self._lock:
            self.responses[(charge_point_identity, unique_id)] = (
                response,
                time.monotonic() + ttl_seconds,
            )

    async def publish(self, stream: str, event: JsonObject, max_length: int) -> None:
        async with self._lock:
            self.streams[stream].append(event)
            if len(self.streams[stream]) > max_length:
                del self.streams[stream][:-max_length]

    async def next_protocol_transaction_id(self, charge_point_identity: str) -> int:
        async with self._lock:
            self.transaction_sequences[charge_point_identity] += 1
            return self.transaction_sequences[charge_point_identity]

    async def authorize(
        self, stream: str, request: JsonObject, timeout_seconds: float, max_length: int
    ) -> AuthorizationDecision:
        await self.publish(stream, request, max_length)
        return self.authorization_decision

    async def close(self) -> None:
        return None


class RedisGatewayStore:
    _renew_script = """
        local current = redis.call('GET', KEYS[1])
        if not current then return 0 end
        local value = cjson.decode(current)
        if value.connection_id ~= ARGV[1] then return 0 end
        redis.call('EXPIRE', KEYS[1], ARGV[2])
        return 1
    """
    _release_script = """
        local current = redis.call('GET', KEYS[1])
        if not current then return 0 end
        local value = cjson.decode(current)
        if value.connection_id ~= ARGV[1] then return 0 end
        redis.call('DEL', KEYS[1])
        return 1
    """

    def __init__(self, url: str, key_prefix: str = "vtsa:local:ocpp") -> None:
        self._redis: Redis = Redis.from_url(url, decode_responses=True)
        self._key_prefix = key_prefix.rstrip(":")

    def _connection_key(self, charge_point_identity: str) -> str:
        return f"{self._key_prefix}:connection:{charge_point_identity}"

    def _message_key(self, charge_point_identity: str, unique_id: str) -> str:
        return f"{self._key_prefix}:message:{charge_point_identity}:{unique_id}"

    def _response_key(self, charge_point_identity: str, unique_id: str) -> str:
        return f"{self._key_prefix}:response:{charge_point_identity}:{unique_id}"

    def authorization_response_key(self, request_id: str) -> str:
        return f"{self._key_prefix}:authorization-response:{request_id}"

    async def ping(self) -> bool:
        try:
            return bool(await self._redis.ping())
        except Exception:
            return False

    async def claim_connection(
        self, charge_point_identity: str, node_id: str, connection_id: str, ttl_seconds: int
    ) -> str | None:
        value = json.dumps(
            {"node_id": node_id, "connection_id": connection_id}, separators=(",", ":")
        )
        previous = await self._redis.set(
            self._connection_key(charge_point_identity), value, ex=ttl_seconds, get=True
        )
        if previous is None:
            return None
        decoded = json.loads(cast(str, previous))
        return cast(str, decoded["connection_id"])

    async def renew_connection(
        self, charge_point_identity: str, connection_id: str, ttl_seconds: int
    ) -> bool:
        result = await cast(
            Awaitable[Any],
            self._redis.eval(
                self._renew_script,
                1,
                self._connection_key(charge_point_identity),
                connection_id,
                str(ttl_seconds),
            ),
        )
        return bool(result)

    async def release_connection(self, charge_point_identity: str, connection_id: str) -> bool:
        result = await cast(
            Awaitable[Any],
            self._redis.eval(
                self._release_script,
                1,
                self._connection_key(charge_point_identity),
                connection_id,
            ),
        )
        return bool(result)

    async def claim_message(
        self, charge_point_identity: str, unique_id: str, ttl_seconds: int
    ) -> bool:
        result = await self._redis.set(
            self._message_key(charge_point_identity, unique_id),
            "processing",
            ex=ttl_seconds,
            nx=True,
        )
        return bool(result)

    async def cached_response(self, charge_point_identity: str, unique_id: str) -> str | None:
        response = await self._redis.get(self._response_key(charge_point_identity, unique_id))
        return cast(str | None, response)

    async def cache_response(
        self, charge_point_identity: str, unique_id: str, response: str, ttl_seconds: int
    ) -> None:
        await self._redis.set(
            self._response_key(charge_point_identity, unique_id), response, ex=ttl_seconds
        )

    async def publish(self, stream: str, event: JsonObject, max_length: int) -> None:
        await self._redis.xadd(
            stream,
            {"event": json.dumps(event, separators=(",", ":"), default=str)},
            maxlen=max_length,
            approximate=True,
        )

    async def next_protocol_transaction_id(self, charge_point_identity: str) -> int:
        key = f"{self._key_prefix}:transaction-sequence:{charge_point_identity}"
        transaction_id = int(await self._redis.incr(key))
        await self._redis.expire(key, 2_592_000)
        return transaction_id

    async def authorize(
        self, stream: str, request: JsonObject, timeout_seconds: float, max_length: int
    ) -> AuthorizationDecision:
        request_id = new_ulid()
        response_key = self.authorization_response_key(request_id)
        await self.publish(stream, {**request, "request_id": request_id}, max_length)
        response = await cast(
            Awaitable[list[Any] | None],
            self._redis.blpop([response_key], timeout=math.ceil(timeout_seconds)),
        )
        if response is None:
            return AuthorizationDecision("Invalid")
        decoded = json.loads(cast(str, response[1]))
        status = decoded.get("status")
        if not isinstance(status, str):
            return AuthorizationDecision("Invalid")
        return AuthorizationDecision(
            status=status,
            expires_at=decoded.get("expires_at"),
            parent_token=decoded.get("parent_token"),
        )

    async def close(self) -> None:
        await self._redis.aclose()
