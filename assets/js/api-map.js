(function () {
    'use strict';

    var rows = Array.prototype.slice.call(document.querySelectorAll('.olama-api-map-table tbody tr[data-api-id]'));
    var testSelected = document.getElementById('olama-api-test-selected');
    var testAll = document.getElementById('olama-api-test-all');
    var clear = document.getElementById('olama-api-clear');
    var selectAll = document.getElementById('olama-api-select-all');
    var groupFilter = document.getElementById('olama-api-group-filter');
    var progress = document.getElementById('olama-api-map-progress');
    var running = false;

    if (!rows.length || !window.OlamaOracleApiMap) {
        return;
    }

    function parameters() {
        return {
            family_id: document.getElementById('olama-api-family-id').value,
            student_id: document.getElementById('olama-api-student-id').value,
            study_year: document.getElementById('olama-api-study-year').value,
            search: document.getElementById('olama-api-search').value
        };
    }

    function setBusy(value) {
        running = value;
        testSelected.disabled = value;
        testAll.disabled = value;
        clear.disabled = value;
    }

    function setRowState(row, state, detail) {
        var status = row.querySelector('.olama-api-result-status');
        var small = row.querySelector('.olama-api-result small');
        status.className = 'olama-api-result-status is-' + state;
        status.textContent = state === 'testing' ? 'Testing…' : (state === 'pass' ? 'Passed' : (state === 'fail' ? 'Failed' : 'Not tested'));
        small.textContent = detail || '';
        row.dataset.testState = state;
        updateSummary();
    }

    function updateSummary() {
        var tested = rows.filter(function (row) { return row.dataset.testState === 'pass' || row.dataset.testState === 'fail'; });
        var passed = tested.filter(function (row) { return row.dataset.testState === 'pass'; });
        document.getElementById('olama-api-tested-count').textContent = tested.length;
        document.getElementById('olama-api-passed-count').textContent = passed.length;
        document.getElementById('olama-api-failed-count').textContent = tested.length - passed.length;
    }

    function resultDetail(data) {
        var parts = [];
        parts.push('HTTP ' + (data.http_status || 0));
        parts.push((data.latency_ms || 0) + ' ms');
        if (data.record_count !== null && typeof data.record_count !== 'undefined') {
            parts.push(data.record_count + ' records');
        }
        parts.push(data.contract_ok ? 'contract valid' : 'contract mismatch');
        if (data.missing_fields && data.missing_fields.length) {
            parts.push('missing: ' + data.missing_fields.join(', '));
        }
        if (!data.ok && data.message) {
            parts.push(data.message);
        }
        return parts.join(' · ');
    }

    async function testRow(row) {
        setRowState(row, 'testing', 'Sending read-only request…');
        var body = new URLSearchParams();
        body.append('action', 'olama_oracle_test_api_endpoint');
        body.append('nonce', OlamaOracleApiMap.nonce);
        body.append('endpoint_id', row.dataset.apiId);
        var params = parameters();
        Object.keys(params).forEach(function (key) { body.append(key, params[key]); });

        try {
            var response = await fetch(OlamaOracleApiMap.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString()
            });
            var payload = await response.json();
            if (!payload || !payload.success) {
                throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Test request failed.');
            }
            setRowState(row, payload.data.ok ? 'pass' : 'fail', resultDetail(payload.data));
        } catch (error) {
            setRowState(row, 'fail', error.message || 'Test request failed.');
        }
    }

    async function run(targetRows) {
        if (running || !targetRows.length) {
            progress.textContent = targetRows.length ? '' : 'Select at least one API.';
            return;
        }
        setBusy(true);
        for (var i = 0; i < targetRows.length; i++) {
            progress.textContent = 'Testing ' + (i + 1) + ' of ' + targetRows.length + '…';
            await testRow(targetRows[i]);
        }
        progress.textContent = 'Completed ' + targetRows.length + ' read-only tests.';
        setBusy(false);
    }

    testSelected.addEventListener('click', function () {
        run(rows.filter(function (row) { return row.querySelector('.olama-api-select').checked && !row.hidden; }));
    });
    testAll.addEventListener('click', function () {
        run(rows.filter(function (row) { return !row.hidden; }));
    });
    clear.addEventListener('click', function () {
        rows.forEach(function (row) { setRowState(row, 'idle', ''); });
        progress.textContent = '';
    });
    selectAll.addEventListener('change', function () {
        rows.forEach(function (row) {
            if (!row.hidden) {
                row.querySelector('.olama-api-select').checked = selectAll.checked;
            }
        });
    });
    groupFilter.addEventListener('change', function () {
        rows.forEach(function (row) {
            row.hidden = groupFilter.value !== '' && row.dataset.apiGroup !== groupFilter.value;
        });
        selectAll.checked = false;
    });
})();
