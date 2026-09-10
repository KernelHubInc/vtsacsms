from collections.abc import Callable
from typing import TypeVar

_F = TypeVar("_F", bound=Callable[..., object])

def on(action: str, *, skip_schema_validation: bool = False) -> Callable[[_F], _F]: ...
