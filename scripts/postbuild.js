const fs = require('fs');
const path = require('path');

const distDir = path.resolve(__dirname, '..', 'assets', 'js');
if (!fs.existsSync(distDir)) {
  console.error('dist directory not found:', distDir);
  process.exit(1);
}

// Find the main JS file(s) in dist (naive approach: first .js file in assets directory)
function findBundle(dir) {
  const files = fs.readdirSync(dir);
  for (const f of files) {
    const p = path.join(dir, f);
    const stat = fs.statSync(p);
    if (stat.isDirectory()) {
      const sub = findBundle(p);
      if (sub) return sub;
    } else if (f.endsWith('.js')) {
      return p;
    }
  }
  return null;
}

const bundle = findBundle(distDir);
if (!bundle) {
  console.error('No js bundle found in', distDir);
  process.exit(1);
}

const target = path.join(distDir, 'spa-nostr-app.bundle.js');
fs.copyFileSync(bundle, target);
console.log('Copied', bundle, '->', target);
