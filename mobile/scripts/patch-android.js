#!/usr/bin/env node
/**
 * Android WebView will not hand a page the camera unless the app itself
 * holds the CAMERA permission — without it getUserMedia fails silently and
 * the delivery scanner shows a black frame.
 *
 * `npx cap add android` generates AndroidManifest.xml from a template, so the
 * permission has to be re-applied after every init/sync. This patches it in
 * place, and is safe to run repeatedly.
 */
const fs = require('fs');
const path = require('path');

const MANIFEST = path.join(__dirname, '..', 'android', 'app', 'src', 'main', 'AndroidManifest.xml');

const PERMISSIONS = [
    'android.permission.CAMERA',
    'android.permission.INTERNET',
    'android.permission.ACCESS_NETWORK_STATE',
];
const FEATURES = [
    { name: 'android.hardware.camera', required: 'false' },
];

if (!fs.existsSync(MANIFEST)) {
    console.log('[patch-android] No AndroidManifest.xml yet — run "npm run android:init" first.');
    process.exit(0);
}

let xml = fs.readFileSync(MANIFEST, 'utf8');
const before = xml;
const added = [];

for (const perm of PERMISSIONS) {
    if (xml.indexOf('android:name="' + perm + '"') !== -1) continue;
    xml = xml.replace(
        /<application/,
        '    <uses-permission android:name="' + perm + '" />\n\n    <application'
    );
    added.push(perm);
}

for (const f of FEATURES) {
    if (xml.indexOf('android:name="' + f.name + '"') !== -1) continue;
    xml = xml.replace(
        /<application/,
        '    <uses-feature android:name="' + f.name + '" android:required="' + f.required + '" />\n\n    <application'
    );
    added.push(f.name + ' (feature)');
}

if (xml === before) {
    console.log('[patch-android] AndroidManifest.xml already has the camera permission — nothing to do.');
    process.exit(0);
}

fs.writeFileSync(MANIFEST, xml, 'utf8');
console.log('[patch-android] Added to AndroidManifest.xml:');
added.forEach(a => console.log('  + ' + a));
console.log('[patch-android] Rebuild the app for the scanner to get camera access.');
