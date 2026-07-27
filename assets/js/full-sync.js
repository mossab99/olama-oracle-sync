(function($) {
    'use strict';

    var state = {
        jobId: parseInt($('[data-olama-progress-card]').attr('data-active-job'), 10) || 0,
        polling: false
    };
    var $startButtons = $('[data-olama-start-job]');
    var $oneButton = $('[data-olama-sync-one]');
    var $card = $('[data-olama-progress-card]');
    var $message = $('[data-olama-full-sync-message]');
    var $title = $('[data-olama-progress-title]');
    var $percent = $('[data-olama-progress-percent]');
    var $bar = $('[data-olama-full-sync-bar]');
    var phases = ['families', 'students', 'employees', 'academic', 'transportation', 'validation'];

    function request(action, data) {
        return $.post(OlamaOracleFullSync.ajaxUrl, $.extend({
            action: action,
            nonce: OlamaOracleFullSync.nonce
        }, data || {}));
    }

    function setBusy(busy) {
        $startButtons.prop('disabled', busy);
        $oneButton.prop('disabled', busy);
        $('.olama-oracle-sync-choice').toggleClass('is-busy', busy);
    }

    function setPhase(current, status) {
        var currentIndex = phases.indexOf(current);
        $('[data-phase]').removeClass('is-active is-complete');
        phases.forEach(function(phase, index) {
            var $phase = $('[data-phase="' + phase + '"]');
            if (status === 'completed' || status === 'completed_with_errors' || index < currentIndex) {
                $phase.addClass('is-complete');
            } else if (index === currentIndex) {
                $phase.addClass('is-active');
            }
        });
    }

    function renderJob(job) {
        var progress = Math.max(0, Math.min(100, parseFloat(job.progress_percentage) || 0));
        var counts = job.counts || {};
        state.jobId = parseInt(job.id, 10) || state.jobId;
        $card.attr('data-active-job', state.jobId);
        $percent.text(Math.round(progress) + '%');
        $bar.css('width', progress + '%');
        $message.text(job.error_summary || job.message || 'جاري تنفيذ المزامنة...');
        $('[data-olama-job-count]').each(function() {
            var key = $(this).attr('data-olama-job-count');
            $(this).text(parseInt(counts[key], 10) || 0);
        });
        setPhase(job.current_phase, job.status);

        $card.removeClass('has-error is-complete is-running');
        if (job.status === 'failed') {
            $title.text('توقفت العملية #' + job.id);
            $card.addClass('has-error');
        } else if (job.done) {
            $title.text('اكتملت العملية #' + job.id);
            $card.addClass('is-complete');
        } else {
            $title.text('العملية #' + job.id + ' قيد التشغيل');
            $card.addClass('is-running');
        }
        setBusy(!job.done);
    }

    function pollJob() {
        if (!state.jobId || state.polling) {
            return;
        }
        state.polling = true;
        request('olama_oracle_sync_job_status', {job_id: state.jobId}).done(function(response) {
            if (!response || !response.success) {
                $message.text(response && response.data ? response.data.message : 'تعذر قراءة حالة العملية.');
                return;
            }
            renderJob(response.data);
            if (!response.data.done) {
                window.setTimeout(pollJob, 3000);
            }
        }).fail(function() {
            $message.text('تعذر الاتصال مؤقتاً. ستتم إعادة المحاولة تلقائياً.');
            window.setTimeout(pollJob, 5000);
        }).always(function() {
            state.polling = false;
        });
    }

    $startButtons.on('click', function() {
        var scope = $(this).attr('data-olama-start-job') || 'complete';
        var studyYear = String($('[data-olama-sync-year]').val() || '').trim();
        if (!studyYear) {
            $message.text('السنة الدراسية النشطة غير محددة في Olama Core.');
            return;
        }

        setBusy(true);
        $card.removeClass('has-error is-complete').addClass('is-running');
        $title.text('جاري إنشاء عملية المزامنة...');
        $message.text('سيتم تنفيذ العملية على الخادم ويمكنك مغادرة هذه الصفحة.');
        request('olama_oracle_start_sync_job', {scope: scope, study_year: studyYear}).done(function(response) {
            if (!response || !response.success) {
                var data = response && response.data ? response.data : {};
                if (data.job_id) {
                    state.jobId = parseInt(data.job_id, 10);
                    pollJob();
                    return;
                }
                $message.text(data.message || 'تعذر بدء عملية المزامنة.');
                $card.addClass('has-error').removeClass('is-running');
                setBusy(false);
                return;
            }
            renderJob(response.data);
            pollJob();
        }).fail(function(xhr) {
            var data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
            if (data.job_id) {
                state.jobId = parseInt(data.job_id, 10);
                pollJob();
                return;
            }
            $message.text(data.message || 'تعذر الاتصال بخادم WordPress لبدء العملية.');
            $card.addClass('has-error').removeClass('is-running');
            setBusy(false);
        });
    });

    $oneButton.on('click', function() {
        var familyId = String($('[data-olama-family-id]').val() || '').trim();
        var studyYear = String($('[data-olama-single-year]').val() || '').trim();
        if (!familyId) {
            $message.text('أدخل رقم العائلة في Oracle أولاً.');
            $('[data-olama-family-id]').trigger('focus');
            return;
        }

        setBusy(true);
        $title.text('جاري مزامنة العائلة ' + familyId);
        $message.text('تحديث العائلة والطلاب والبيانات المرتبطة...');
        request('olama_oracle_sync_one_family', {family_id: familyId, study_year: studyYear}).done(function(response) {
            var data = response && response.data ? response.data : {};
            if (!response || !response.success) {
                $message.text(data.message || 'تعذر مزامنة العائلة.');
                $card.addClass('has-error');
                return;
            }
            $percent.text('100%');
            $bar.css('width', '100%');
            $title.text('اكتملت مزامنة العائلة ' + familyId);
            $message.text(data.message || 'اكتملت المزامنة.');
            $card.addClass('is-complete').removeClass('has-error');
        }).fail(function() {
            $message.text('تعذر الاتصال بخادم WordPress أثناء مزامنة العائلة.');
            $card.addClass('has-error');
        }).always(function() {
            setBusy(false);
        });
    });

    if (state.jobId) {
        setBusy(true);
        pollJob();
    }
})(jQuery);
