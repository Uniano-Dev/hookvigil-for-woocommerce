// Boots WordPress Playground with WooCommerce and the plugin, runs tests/scenarios/run-all.php.
// Usage: node tests/run-scenarios.mjs [phpVersion]   (default 7.4)
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import os from 'node:os';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const php = process.argv[2] || '7.4';
const cli = path.join(root, 'tools', 'node_modules', '@wp-playground', 'cli', 'wp-playground.js');

// The --php flag is ignored when a blueprint is passed, so the version goes into a temporary blueprint copy.
const blueprint = JSON.parse(readFileSync(path.join(root, 'tests', 'blueprint.json'), 'utf8'));
blueprint.preferredVersions = { php, wp: 'latest' };
const blueprintPath = path.join(mkdtempSync(path.join(os.tmpdir(), 'wwfw-')), 'blueprint.json');
writeFileSync(blueprintPath, JSON.stringify(blueprint));

const args = [
  cli, 'php',
  '--blueprint', blueprintPath,
  '--mount-dir', path.join(root, 'plugin', 'hookvigil-for-woocommerce'), '/wordpress/wp-content/plugins/hookvigil-for-woocommerce',
  '--mount-dir', path.join(root, 'tests'), '/tests',
  '--', '/tests/scenarios/run-all.php',
];

const run = spawnSync(process.execPath, args, { encoding: 'utf8', env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
const out = `${run.stdout || ''}${run.stderr || ''}`;
process.stdout.write(out);

const failed = /^FAIL:/m.test(out) || !/^RESULT:/m.test(out);
process.exit(failed ? 1 : 0);
