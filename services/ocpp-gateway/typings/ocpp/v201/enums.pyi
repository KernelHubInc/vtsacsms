from enum import StrEnum

class Action(StrEnum):
    authorize = "Authorize"
    boot_notification = "BootNotification"
    data_transfer = "DataTransfer"
    firmware_status_notification = "FirmwareStatusNotification"
    heartbeat = "Heartbeat"
    log_status_notification = "LogStatusNotification"
    meter_values = "MeterValues"
    security_event_notification = "SecurityEventNotification"
    status_notification = "StatusNotification"
    transaction_event = "TransactionEvent"

class AuthorizationStatusEnumType(StrEnum):
    accepted = "Accepted"
    blocked = "Blocked"
    concurrent_tx = "ConcurrentTx"
    expired = "Expired"
    invalid = "Invalid"

class RegistrationStatusEnumType(StrEnum):
    accepted = "Accepted"

class DataTransferStatusEnumType(StrEnum):
    accepted = "Accepted"
    rejected = "Rejected"
    unknown_message_id = "UnknownMessageId"
    unknown_vendor_id = "UnknownVendorId"
