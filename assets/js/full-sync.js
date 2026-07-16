(function($) {
    'use strict';

    var state = {
        running: false,
        nextOffset: 0,
        limit: Math.max(1, Math.min(100, parseInt(OlamaOracleFullSync.batchSize, 10) || 25))
    };

    var $allButton = $('[data-olama-sync-all]');
    var $oneButton = $('[data-olama-sync-one]');
    var $message = $('[data-olama-full-sync-message]');
    var $title = $('[data-olama-progress-title]');
    var $percent = $('[data-olama-progress-percent]');
    var $bar = $('[data-olama-full-sync-bar]');
    var $links = $('[data-olama-full-sync-links]');

    function field(name) {
        return $('[data-olama-full-sync-field="' + name + '"]');
    }

    function allStudyYear() {
        return String($('[data-olama-sync-year]').val() || '').trim();
    }

    function singleStudyYear() {
        return String($('[data-olama-single-year]').val() || '').trim();
    }

    function familyId() {
        return String($('[data-olama-family-id]').val() || '').trim();
    }

    function request(action, data) {
        return $.post(OlamaOracleFullSync.ajaxUrl, $.extend({
            action: action,
            nonce: OlamaOracleFullSync.nonce
        }, data || {}));
    }

    function setRunning(running) {
        state.running = running;
        $allButton.prop('disabled', running);
        $oneButton.prop('disabled', running);
        $('.olama-oracle-sync-choice').toggleClass('is-busy', running);
    }

    function setPhase(phase) {
        $('.olama-oracle-phase-list [data-phase]').removeClass('is-active is-complete');
        if (phase === 'families') {
            $('[data-phase="families"]').addClass('is-active');
        } else if (phase === 'students') {
            $('[data-phase="families"]').addClass('is-complete');
            $('[data-phase="students"]').addClass('is-active');
        } else if (phase === 'completed') {
            $('[data-phase="families"], [data-phase="students"], [data-phase="completed"]').addClass('is-complete');
        }
    }

    function updateProgress(data) {
        data = data || {};
        var progress = Math.max(0, Math.min(100, parseFloat(data.progress_percentage) || 0));
        var studentsChanged = (parseInt(data.students_inserted, 10) || 0) + (parseInt(data.students_updated, 10) || 0);
        var yearsChanged = (parseInt(data.student_year_rows_inserted, 10) || 0) + (parseInt(data.student_year_rows_updated, 10) || 0);
        var unchanged = (parseInt(data.families_skipped, 10) || 0) + (parseInt(data.students_skipped, 10) || 0) + (parseInt(data.student_year_rows_skipped, 10) || 0);

        if (data.phase === 'families' && progress === 0) {
            progress = 4;
        }
        if (data.done) {
            progress = 100;
        }

        $percent.text(Math.round(progress) + '%');
        $bar.css('width', progress + '%');
        $message.text(data.message || 'جاري تنفيذ المزامنة...');
        field('families_processed').text(parseInt(data.families_processed, 10) || 0);
        field('students_changed').text(studentsChanged);
        field('student_years_changed').text(yearsChanged);
        field('unchanged').text(unchanged);
        field('errors').text(parseInt(data.errors, 10) || 0);
        $('[data-olama-last-family]').text(data.last_family_id ? 'آخر عائلة تمت معالجتها: ' + data.last_family_id : '');
        setPhase(data.phase || 'students');

        if (data.status === 'failed') {
            $title.text('تعذر إكمال المزامنة');
            $('[data-olama-progress-card]').addClass('has-error').removeClass('is-complete');
        } else if (data.done) {
            $title.text('اكتملت المزامنة');
            $('[data-olama-progress-card]').addClass('is-complete').removeClass('has-error');
        } else if (data.phase === 'families') {
            $title.text('مزامنة العائلات');
        } else {
            $title.text('مزامنة الطلاب وبيانات السنة');
        }

        $links.prop('hidden', !data.done);
        if (typeof data.next_offset !== 'undefined') {
            state.nextOffset = parseInt(data.next_offset, 10) || 0;
        }
    }

    function showFailure(response, fallback) {
        var data = response && response.data ? response.data : {};
        data.status = 'failed';
        data.message = data.message || fallback;
        updateProgress(data);
        setRunning(false);
    }

    function runAllBatch(studyYear, offset) {
        request('olama_oracle_run_students_full_sync_batch', {
            study_year: studyYear,
            limit: state.limit,
            offset: offset,
            mode: 'students'
        }).done(function(response) {
            if (!response || !response.success) {
                showFailure(response, 'تعذر إكمال دفعة المزامنة.');
                return;
            }

            updateProgress(response.data);
            if (response.data.done) {
                setRunning(false);
                return;
            }
            window.setTimeout(function() {
                runAllBatch(studyYear, response.data.next_offset);
            }, 250);
        }).fail(function() {
            showFailure(null, 'تعذر الاتصال بخادم WordPress أثناء المزامنة.');
        });
    }

    $allButton.on('click', function() {
        var studyYear = allStudyYear();
        if (!studyYear) {
            $message.text('أدخل السنة الدراسية أولاً.');
            $('[data-olama-sync-year]').trigger('focus');
            return;
        }

        setRunning(true);
        state.nextOffset = 0;
        $links.prop('hidden', true);
        $('[data-olama-progress-card]').removeClass('has-error is-complete');
        updateProgress({phase: 'families', status: 'running', message: 'جاري تحديث دليل العائلات من Oracle...'});

        request('olama_oracle_start_all_sync', {study_year: studyYear}).done(function(response) {
            if (!response || !response.success) {
                showFailure(response, 'تعذر بدء مزامنة العائلات.');
                return;
            }
            updateProgress(response.data);
            if (response.data.done) {
                setRunning(false);
                return;
            }
            runAllBatch(studyYear, response.data.next_offset || 0);
        }).fail(function() {
            showFailure(null, 'تعذر الاتصال بخادم WordPress لبدء المزامنة.');
        });
    });

    $oneButton.on('click', function() {
        var id = familyId();
        var studyYear = singleStudyYear();
        if (!id) {
            $message.text('أدخل رقم العائلة في Oracle أولاً.');
            $('[data-olama-family-id]').trigger('focus');
            return;
        }
        if (!studyYear) {
            $message.text('أدخل السنة الدراسية أولاً.');
            $('[data-olama-single-year]').trigger('focus');
            return;
        }

        setRunning(true);
        $links.prop('hidden', true);
        $('[data-olama-progress-card]').removeClass('has-error is-complete');
        updateProgress({phase: 'families', status: 'running', message: 'جاري مزامنة العائلة رقم ' + id + '...'});

        request('olama_oracle_sync_one_family', {
            family_id: id,
            study_year: studyYear
        }).done(function(response) {
            if (!response || !response.success) {
                showFailure(response, 'تعذر مزامنة العائلة المحددة.');
                return;
            }
            updateProgress(response.data);
            setRunning(false);
        }).fail(function() {
            showFailure(null, 'تعذر الاتصال بخادم WordPress أثناء مزامنة العائلة.');
        });
    });
})(jQuery);
