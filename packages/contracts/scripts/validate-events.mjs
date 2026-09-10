import { readFile } from 'node:fs/promises';

import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';

const ajv = new Ajv2020({ allErrors: true, strict: true });
addFormats(ajv);

const contracts = [
  ['event-envelope.v1.schema.json', 'charging.session.completed.v1.json'],
  ['gateway-ocpp-normalized.v1.schema.json', 'gateway.ocpp.meter_values.received.v1.json'],
];

for (const [schemaFile, exampleFile] of contracts) {
  const schema = JSON.parse(await readFile(`events/${schemaFile}`, 'utf8'));
  const example = JSON.parse(await readFile(`events/examples/${exampleFile}`, 'utf8'));
  const validate = ajv.compile(schema);

  if (!validate(example)) {
    throw new Error(`${exampleFile} is invalid: ${ajv.errorsText(validate.errors)}`);
  }

  const invalid = structuredClone(example);
  invalid.event_id = 'not-a-ulid';

  if (validate(invalid)) {
    throw new Error(`${schemaFile} accepted an invalid event identifier.`);
  }
}

process.stdout.write('Event schemas and fixtures are valid.\n');
