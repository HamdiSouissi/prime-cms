#!/usr/bin/env node
/**
 * Prime CMS — Custom zero-dependency ES2022 JS test runner (Node 18+).
 * Runs all `tests/js/*.test.mjs` test suites cleanly without any npm dependencies.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const suites = [];
let currentSuite = null;

export function describe(name, fn) {
  const suite = { name, tests: [] };
  suites.push(suite);
  const prev = currentSuite;
  currentSuite = suite;
  fn();
  currentSuite = prev;
}

export function test(name, fn) {
  if (!currentSuite) {
    suites.push({ name: 'default', tests: [{ name, fn }] });
  } else {
    currentSuite.tests.push({ name, fn });
  }
}

async function main() {
  const entries = fs
    .readdirSync(__dirname)
    .filter((file) => file.endsWith('.test.mjs'))
    .sort();

  for (const file of entries) {
    await import(pathToFileURL(path.join(__dirname, file)).href);
  }

  let passed = 0;
  let failed = 0;
  const failures = [];

  for (const suite of suites) {
    for (const tc of suite.tests) {
      try {
        await tc.fn();
        passed += 1;
      } catch (err) {
        failed += 1;
        failures.push({
          label: `${suite.name} › ${tc.name}`,
          error: err instanceof Error ? err.stack || err.message : String(err),
        });
      }
    }
  }

  console.log(`Prime JS Tests: ${passed} passed, ${failed} failed`);
  if (failures.length > 0) {
    console.error('\nFAILURES:');
    for (const f of failures) {
      console.error(`\n✖ ${f.label}\n${f.error}`);
    }
    process.exit(1);
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch((err) => {
    console.error(err);
    process.exit(1);
  });
}
