<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

enum PermissionKey: string
{
    case IdentityContextView = 'identity.context.view';
    case PlatformPanelAccess = 'identity.panels.platform.access';
    case OperatorPanelAccess = 'identity.panels.operator.access';
    case MembershipView = 'identity.memberships.view';
    case MembershipManage = 'identity.memberships.manage';
    case InvitationManage = 'identity.invitations.manage';
    case RoleView = 'identity.roles.view';
    case RoleManage = 'identity.roles.manage';
    case RoleAssign = 'identity.roles.assign';
    case TokenIssue = 'identity.tokens.issue';
    case TokenRevoke = 'identity.tokens.revoke';
    case SessionView = 'identity.sessions.view';
    case SessionRevoke = 'identity.sessions.revoke';
    case AuditView = 'audit.events.view';
    case AuditExport = 'audit.events.export';
    case TenantSettingsView = 'tenancy.settings.view';
    case TenantSettingsManage = 'tenancy.settings.manage';
    case OrganizationView = 'organizations.view';
    case OrganizationManage = 'organizations.manage';
    case LocationView = 'locations.view';
    case LocationManage = 'locations.manage';
    case AssetView = 'assets.view';
    case AssetManage = 'assets.manage';
    case ChargingSessionView = 'charging.sessions.view';
    case ChargingRemoteCommand = 'charging.remote_commands.execute';
    case ChargingSessionReview = 'charging.sessions.review';
    case TariffView = 'tariffs.view';
    case TariffManage = 'tariffs.manage';
    case TariffPublish = 'tariffs.publish';
    case PaymentView = 'payments.view';
    case PaymentRefund = 'payments.refunds.execute';
    case BillingView = 'billing.view';
    case SettlementView = 'settlements.view';
    case SettlementPrepare = 'settlements.prepare';
    case SettlementApprove = 'settlements.approve';
    case ProcurementView = 'procurement.view';
    case ProcurementManage = 'procurement.manage';
    case ProcurementApprove = 'procurement.approve';
    case InventoryView = 'inventory.view';
    case InventoryOperate = 'inventory.operate';
    case InventoryAdjust = 'inventory.adjust';
    case MaintenanceView = 'maintenance.view';
    case MaintenanceDispatch = 'maintenance.dispatch';
    case MaintenancePerform = 'maintenance.perform';
    case MaintenanceVerify = 'maintenance.verify';
    case SupportView = 'support.view';
    case SupportManage = 'support.manage';
    case SupportSensitiveReveal = 'support.sensitive_reveal';
    case ReportingView = 'reporting.view';
    case ReportingExport = 'reporting.export';
    case CmsEdit = 'cms.edit';
    case CmsPublish = 'cms.publish';
    case IntegrationView = 'integrations.view';
    case IntegrationManage = 'integrations.manage';
}
