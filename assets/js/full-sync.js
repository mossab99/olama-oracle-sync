(function($) {
    'use strict';

    var state = {
        mode: 'students',
        running: false,
        paused: false,
        nextOffset: 0
    };

    function field(name) {
        return $('[data-olama-full-sync-field="' + name + '"]');
    }

    function studyYear() {
        return String($('[data-olama-full-sync-study-year]').val() || '').trim();
    }

    function limit() {
        var value = parseInt($('[data-olama-full-sync-limit]').val(), 10);
        if (!value || value < 1) {
            value = 1;
        }
        if (value > 100) {
            value = 100;
        }
        $('[data-olama-full-sync-limit]').val(value);
        return value;
    }

    function startOffset() {
        var value = parseInt($('[data-olama-full-sync-offset]').val(), 10);
        if (!value || value < 0) {
            value = 0;
        }
        $('[data-olama-full-sync-offset]').val(value);
        return value;
    }

    function actionForMode(mode) {
        return mode === 'student_years'
            ? 'olama_oracle_run_student_years_full_sync_batch'
            : 'olama_oracle_run_students_full_sync_batch';
    }

    function updateProgress(data) {
        var percent = data.progress_percentage || 0;
        field('status').text(data.status || '-');
        field('study_year').text(data.study_year || '-');
        field('last_offset').text(data.offset || 0);
        field('total_families').text(data.total_families || 0);
        field('families_processed').text(data.families_processed || 0);
        field('total_students_inserted').text(data.students_inserted || 0);
        field('total_students_updated').text(data.students_updated || 0);
        field('total_student_year_rows_inserted').text(data.student_year_rows_inserted || 0);
        field('total_student_year_rows_updated').text(data.student_year_rows_updated || 0);
        field('total_errors').text(data.errors || 0);
        field('progress_percentage').text(percent + '%');
        field('last_family_id').text(data.last_family_id || '-');
        $('[data-olama-full-sync-bar]').css('width', percent + '%');
        $('[data-olama-full-sync-message]').text(data.message || '');
        $('[data-olama-full-sync-links]').toggle(!!data.done);
        if (typeof data.next_offset !== 'undefined') {
            state.nextOffset = parseInt(data.next_offset, 10) || 0;
            $('[data-olama-full-sync-offset]').val(state.nextOffset);
        }
    }

    function setStatus(status) {
        return $.post(OlamaOracleFullSync.ajaxUrl, {
            action: 'olama_oracle_update_full_sync_progress',
            nonce: OlamaOracleFullSync.nonce,
            mode: state.mode,
            study_year: studyYear(),
            status: status
        });
    }

    function runBatch(offset) {
        if (!state.running || state.paused) {
            return;
        }

        $('[data-olama-full-sync-message]').text('Running batch at offset ' + offset + '...');
        $.post(OlamaOracleFullSync.ajaxUrl, {
            action: actionForMode(state.mode),
            nonce: OlamaOracleFullSync.nonce,
            study_year: studyYear(),
            limit: limit(),
            offset: offset,
            mode: state.mode
        }).done(function(response) {
            var data = response && response.data ? response.data : {};
            if (!response || !response.success) {
                state.running = false;
                state.paused = true;
                updateProgress(data);
                $('[data-olama-full-sync-message]').text(data.message || 'Sync failed.');
                return;
            }

            updateProgress(data);
            if (data.done) {
                state.running = false;
                state.paused = false;
                return;
            }

            window.setTimeout(function() {
                runBatch(data.next_offset);
            }, 350);
        }).fail(function(xhr) {
            state.running = false;
            state.paused = true;
            $('[data-olama-full-sync-message]').text('Request failed: HTTP ' + xhr.status + '.');
        });
    }

    $('[data-olama-full-sync-start]').on('click', function() {
        state.mode = $(this).data('olama-full-sync-start');
        state.running = true;
        state.paused = false;
        $('[data-olama-full-sync-links]').hide();
        setStatus('running').always(function() {
            runBatch(startOffset());
        });
    });

    $('[data-olama-full-sync-pause]').on('click', function() {
        state.paused = true;
        state.running = false;
        setStatus('paused');
        $('[data-olama-full-sync-message]').text('Paused. Click resume to continue from the stored next offset.');
    });

    $('[data-olama-full-sync-resume]').on('click', function() {
        state.running = true;
        state.paused = false;
        setStatus('running').always(function() {
            runBatch(startOffset());
        });
    });

    $('[data-olama-full-sync-reset]').on('click', function() {
        state.running = false;
        state.paused = true;
        $.post(OlamaOracleFullSync.ajaxUrl, {
            action: 'olama_oracle_reset_full_sync_progress',
            nonce: OlamaOracleFullSync.nonce,
            mode: state.mode,
            study_year: studyYear()
        }).done(function(response) {
            var data = response && response.data ? response.data : {};
            $('[data-olama-full-sync-offset]').val(0);
            $('[data-olama-full-sync-bar]').css('width', '0');
            updateProgress({
                status: data.status || 'paused',
                study_year: data.study_year || studyYear(),
                offset: 0,
                next_offset: 0,
                total_families: data.total_families || 0,
                families_processed: 0,
                students_inserted: 0,
                students_updated: 0,
                student_year_rows_inserted: 0,
                student_year_rows_updated: 0,
                errors: 0,
                progress_percentage: 0,
                last_family_id: '',
                message: 'Progress reset.'
            });
        });
    });
})(jQuery);
