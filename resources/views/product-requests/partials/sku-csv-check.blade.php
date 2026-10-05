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

        const splitRow = (line) => {
            const out = [];
            let cell = '', quoted = false;
            for (let i = 0; i < line.length; i++) {
                const c = line[i];
                if (quoted) {
                    if (c === '"' && line[i + 1] === '"') { cell += '"'; i++; }
                    else if (c === '"') quoted = false;
                    else cell += c;
                } else if (c === '"') quoted = true;
                else if (c === ',') { out.push(cell); cell = ''; }
                else cell += c;
            }
            out.push(cell);
            return out;
        };

        const text  = (await file.text()).replace(/^﻿/, '');   // Excel's BOM
        const lines = text.split(/\r\n|\r|\n/).filter(l => l.replace(/[\s,]/g, '') !== '');

        if (!lines.length) return invalid;

        const headers = splitRow(lines.shift()).map(h => h.trim().replace(/\s+/g, ' ').toLowerCase());
        const column  = headers.findIndex(h => h === 'sku' || h === 'item sku');

        if (column === -1) return invalid;

        if (!lines.some(l => (splitRow(l)[column] || '').trim() !== '')) {
            return 'The CSV has a SKU column but no SKUs under it. Please check the file and upload it again.';
        }

        return null;
    };
</script>
@endonce
