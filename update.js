#!/usr/bin/env node
/**
 * Quick update script for AutoPoster (cross-platform).
 * Run: node update.js  OR  npm run update
 * No need to stop the server — nodemon + React hot-reload handle restarts.
 */
const { execSync } = require('child_process');

function run(cmd) {
  try {
    return execSync(cmd, { stdio: 'inherit', encoding: 'utf8' });
  } catch {
    return null;
  }
}

console.log('\nPulling latest changes...');
const pullResult = run('git pull');

if (pullResult === null) {
  console.log('Git pull failed. Make sure you have git configured and network access.');
  process.exit(1);
}

// Check if package.json changed (need npm install)
let changed = '';
try {
  changed = execSync('git diff --name-only HEAD@{1} HEAD', { encoding: 'utf8' });
} catch { /* ignore */ }

if (changed.includes('package.json')) {
  console.log('\nDependencies changed — installing...');
  run('npm --prefix backend install');
  run('npm --prefix frontend install');
  console.log('Dependencies updated.');
}

console.log('\nDone! Both servers will auto-reload with the new code.');
console.log('  - Backend: nodemon detects file changes and restarts');
console.log('  - Frontend: React hot-reloads in your browser');
console.log('\nNo need to restart anything.\n');
