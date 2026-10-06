{{-- Checks a SKU CSV the moment it is picked, with the same rules the server
     applies on submit (ProductRequestController::skusFromCsv), so a wrong file
     is caught before the rest of the form is filled in. The server still
     checks: this is for the user's benefit, not a gate. --}}
@once
<script>
    window.checkSkuCsv = async function (file) {
        if (!file) return null;

        const invalid = 'Please upload a valid CSV. Its first row must have a column named "SKU" or "Item SKU".';

        if (!/\.(csv|txt)$/i.test(file.name)) return invalid;

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

        if (!rows.length) return invalid;

        const headers = rows.shift().map(h => h.trim().replace(/\s+/g, ' ').toLowerCase());
        const column  = headers.findIndex(h => h === 'sku' || h === 'item sku');

        if (column === -1) return invalid;

        if (!rows.some(r => (r[column] || '').trim() !== '')) {
            return 'The CSV has a SKU column but no SKUs under it. Please check the file and upload it again.';
        }

        return null;
    };
</script>
@endonce
