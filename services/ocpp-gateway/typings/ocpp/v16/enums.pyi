from enum import StrEnum

class Action(StrEnum):
    authorize = "Authorize"
    boot_notification = "BootNotification"
    data_transfer = "DataTransfer"
    diagnostics_status_notification = "DiagnosticsStatusNotification"
    firmware_status_notification = "FirmwareStatusNotification"
    heartbeat = "Heartbeat"
    meter_values = "MeterValues"
    security_event_notification = "SecurityEventNotification"
    start_transaction = "StartTransaction"
    status_notification = "StatusNotification"
    stop_transaction = "StopTransaction"

class AuthorizationStatus(StrEnum):
    accepted = "Accepted"
    blocked = "Blocked"
    concurrent_tx = "ConcurrentTx"
    expired = "Expired"
    invalid = "Invalid"

class RegistrationStatus(StrEnum):
    accepted = "Accepted"

class DataTransferStatus(StrEnum):
    accepted = "Accepted"
    rejected = "Rejected"
    unknown_message_id = "UnknownMessageId"
    unknown_vendor_id = "UnknownVendorId"
