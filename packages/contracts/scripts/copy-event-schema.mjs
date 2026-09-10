import { cp, mkdir } from 'node:fs/promises';

await mkdir('generated', { recursive: true });
await cp('events/event-envelope.v1.schema.json', 'generated/event-envelope.v1.schema.json');
await cp(
  'events/gateway-ocpp-normalized.v1.schema.json',
  'generated/gateway-ocpp-normalized.v1.schema.json',
);
