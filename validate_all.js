const fs = require('fs');
const path = require('path');
const parser = require('./.phptools/node_modules/php-parser');
const errors = [];
function walk(dir) {
  for (const entry of fs.readdirSync(dir, {withFileTypes:true})) {
    const p = path.join(dir, entry.name);
    if (entry.isDirectory()) { walk(p); continue; }
    if (entry.name.endsWith('.php')) {
      try { parser.parseCode(fs.readFileSync(p, 'utf8')); }
      catch(e) { errors.push(p + ': ' + e.message.slice(0,120)); }
    }
  }
}
walk('app'); walk('route');
if (fs.existsSync('tools')) walk('tools');
if (errors.length) { console.log('ERRORS:\n' + errors.join('\n')); process.exit(1); }
else { console.log('ALL PHP OK'); }
