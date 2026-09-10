from typing import Any

class ChargePoint:
    id: str
    def __init__(
        self,
        id: str,
        connection: Any,
        response_timeout: float = 30,
        logger: Any = ...,
    ) -> None: ...
    async def call(
        self,
        payload: Any,
        suppress: bool = True,
        unique_id: str | None = None,
        skip_schema_validation: bool = False,
    ) -> Any: ...
    async def route_message(self, raw_msg: str) -> None: ...
    async def start(self) -> None: ...
