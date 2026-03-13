const fs = require(\"fs\"
const path = require(\"path\"); 
 
const dir = path.join(__dirname, \"..\"); 
const files = fs.readdirSync(dir).filter((f) => f.endsWith(\".html\")); 
 
for (const f of files) { 
  const p = path.join(dir, f); 
  let c = fs.readFileSync(p, \"utf8\"); 
 
  c = c.replace(/.*cdn\.tailwindcss\.com.*\r?\n/gm, \"\"); 
 
  if (!c.includes(boot) && !c.includes(\"bootstrap.min.css\")) { 
    if (c.includes(\"</head>\")) { 
      c = c.replace(\"</head>\", \"    \" + boot + \"\n</head>\"); 
    } 
  } 
 
  if (!c.includes(\"Css/tw-compat.css\")) { 
    if (c.includes(boot)) { 
      c = c.replace(boot, boot + \"\n\" + compat); 
    } else if (c.includes(\"</head>\")) { 
      c = c.replace(\"</head>\", compat + \"\n</head>\"); 
    } 
  } 
 
  fs.writeFileSync(p, c, \"utf8\"); 
}) )
