#!/usr/bin/env node

// Simple script to convert HTML to PDF
// Run: node generate-pdf.js

const fs = require('fs');
const path = require('path');

console.log('📄 Security Report Files Generated:');
console.log('');
console.log('1. Markdown Report: SECURITY_ISSUES_REPORT.md');
console.log('2. HTML Report: security-report.html');
console.log('');
console.log('🔄 To convert HTML to PDF:');
console.log('');
console.log('Option 1 - Using Browser:');
console.log('  1. Open security-report.html in your browser');
console.log('  2. Press Ctrl+P (or Cmd+P on Mac)');
console.log('  3. Select "Save as PDF"');
console.log('  4. Choose destination and save');
console.log('');
console.log('Option 2 - Using puppeteer (if installed):');
console.log('  npm install puppeteer');
console.log('  node -e "');
console.log('    const puppeteer = require(\'puppeteer\');');
console.log('    (async () => {');
console.log('      const browser = await puppeteer.launch();');
console.log('      const page = await browser.newPage();');
console.log('      await page.goto(\'file://\' + __dirname + \'/security-report.html\');');
console.log('      await page.pdf({');
console.log('        path: \'jitume-security-report.pdf\',');
console.log('        format: \'A4\',');
console.log('        printBackground: true,');
console.log('        margin: { top: \'20px\', bottom: \'20px\', left: \'20px\', right: \'20px\' }');
console.log('      });');
console.log('      await browser.close();');
console.log('      console.log(\'PDF generated: jitume-security-report.pdf\');');
console.log('    })();');
console.log('  "');
console.log('');
console.log('Option 3 - Using wkhtmltopdf (if installed):');
console.log('  wkhtmltopdf security-report.html jitume-security-report.pdf');
console.log('');
console.log('✅ Files are ready for download and PDF conversion!');