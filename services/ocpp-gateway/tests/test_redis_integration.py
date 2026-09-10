from __future__ import annotations

import asyncio
import json
import os
from collections.abc import Awaitable
from typing import Any, cast

import pytest
from redis.asyncio import Redis

from vtsa_ocpp_gateway.ids import new_ulid
from vtsa_ocpp_gateway.store import RedisGatewayStore


@pytest.mark.integration
@pytest.mark.asyncio
async def test_redis_store_coordinates_ownership_deduplication_and_authorization() -> None:
    redis_url = os.getenv("TEST_REDIS_URL")
    if redis_url is None:
        pytest.skip("TEST_REDIS_URL is not configured")
    key_prefix = f"vtsa:test:ocpp:{new_ulid()}"
    store = RedisGatewayStore(redis_url, key_prefix)
    redis: Redis = Redis.from_url(redis_url, decode_responses=True)
    identity = f"TEST-{new_ulid()}"
    first_connection = new_ulid()
    second_connection = new_ulid()
    message_id = new_ulid()
    authorization_stream = f"test:vtsa:ocpp:authorization:{new_ulid()}"
    stream_entry_id: str | None = None
    try:
        assert await store.ping() is True
        assert await store.claim_connection(identity, "node-a", first_connection, 30) is None
        assert (
            await store.claim_connection(identity, "node-b", second_connection, 30)
            == first_connection
        )
        assert await store.release_connection(identity, first_connection) is False
        assert await store.renew_connection(identity, second_connection, 30) is True

        claims = await asyncio.gather(
            *(store.claim_message(identity, message_id, 60) for _ in range(20))
        )
        assert claims.count(True) == 1
        await store.cache_response(identity, message_id, '[3,"id",{}]', 60)
        assert await store.cached_response(identity, message_id) == '[3,"id",{}]'

        authorization = asyncio.create_task(
            store.authorize(
                authorization_stream,
                {"correlation_id": new_ulid(), "token": {"id_tag": "synthetic"}},
                2,
                100,
            )
        )
        request: dict[str, Any] | None = None
        for _ in range(200):
            entries = await redis.xrevrange(authorization_stream, count=1)
            if entries:
                stream_entry_id = cast(str, entries[0][0])
                request = json.loads(cast(str, entries[0][1]["event"]))
                break
            await asyncio.sleep(0.01)
        assert request is not None
        response_key = store.authorization_response_key(cast(str, request["request_id"]))
        await cast(
            Awaitable[Any],
            redis.rpush(response_key, json.dumps({"status": "Accepted"})),
        )
        await redis.expire(response_key, 10)

        decision = await authorization

        assert decision.status == "Accepted"
        assert await store.release_connection(identity, second_connection) is True
    finally:
        if stream_entry_id is not None:
            await redis.xdel(authorization_stream, stream_entry_id)
        await redis.delete(authorization_stream)
        await redis.aclose()
        await store.close()
