import { deadlineTests } from "./regression/deadlines.mjs";
import { weekPreferenceTests } from './regression/week-preferences.mjs'
import { foodAlertTests } from './regression/food-alerts.mjs'
import { travelTests } from './regression/travel.mjs'
import { isolationTests } from "./regression/isolation.mjs";
import { before, after } from "node:test";
import { startEnvironment } from "./support/environment.mjs";
import { savingTests } from "./regression/saving.mjs";
import { privacyTests } from "./regression/privacy.mjs";
import { permissionsTests } from "./regression/permissions.mjs";
import { concurrencyTests } from "./regression/concurrency.mjs";
let environment;
before(async () => {
  environment = await startEnvironment();
});
after(async () => {
  await environment?.stop();
});
const current = () => environment;
weekPreferenceTests(current)
foodAlertTests(current)
travelTests(current)
savingTests(current);
privacyTests(current);
permissionsTests(current);
concurrencyTests(current);
isolationTests(current);

deadlineTests(current);
