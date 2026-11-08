{{-- Checks a SKU file the moment it is picked, with the same rules the server
     applies on submit (ProductRequestController::skusFromCsv), so a wrong file
     is caught before the rest of the form is filled in. The server still
     checks: this is for the user's benefit, not a gate. An Excel file is left
     to the server, which is the one that can open it. --}}
@once
<script>
    window.checkSkuCsv = async function (file) {
        if (!file) return null;

        if (/\.xlsx$/i.test(file.name)) return null;

        if (!/\.(csv|txt)$/i.test(file.name)) {
            return 'Please upload an Excel (.xlsx) or CSV file. An old .xls file can be saved as .xlsx from Excel.';
        }

        // A real CSV read: quoted cells may hold commas and line breaks, which a
        // Long Description often does.
        const parse = (text) => {
            const rows = [];
            let row = [], cell = '', quoted = false;
            for (let i = 0; i < text.length; i++) {
                const c = text[i];
                if (quoted) {
                    if (c === '"' && text[i + 1] === '"') { cell += '"'; i++; }
                    else if (c === '"') quoted = false;
                    else cell += c;
                } else if (c === '"') quoted = true;
                else if (c === ',') { row.push(cell); cell = ''; }
                else if (c === '\n' || c === '\r') {
                    if (c === '\r' && text[i + 1] === '\n') i++;
                    row.push(cell); rows.push(row); row = []; cell = '';
                } else cell += c;
            }
            row.push(cell); rows.push(row);
            return rows.filter(r => r.join('').trim() !== '');
        };

        const rows = parse((await file.text()).replace(/^\uFEFF/, ''));   // Excel's BOM

        if (!rows.length) return 'The file is empty. Please check it and upload it again.';

        // The SKU or Item SKU column, else the first one.
        const headers = rows[0].map(h => h.trim().replace(/\s+/g, ' ').toLowerCase());
        const column  = Math.max(0, headers.findIndex(h => h === 'sku' || h === 'item sku'));

        if (!rows.some(r => (r[column] || '').trim() !== '')) {
            return 'There are no SKUs in the file. Please check it and upload it again.';
        }

        return null;
    };
</script>
@endonce
