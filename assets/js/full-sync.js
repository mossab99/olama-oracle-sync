(function($) {
    'use strict';

    var state = {
        jobId: parseInt($('[data-olama-progress-card]').attr('data-active-job'), 10) || 0,
        polling: false,
        jobBusy: false,
        bridgeConnected: false,
        checkingBridge: false,
        lastJob: null
    };
    var $startButtons = $('[data-olama-start-job]');
    var $oneButton = $('[data-olama-sync-one]');
    var $card = $('[data-olama-progress-card]');
    var $message = $('[data-olama-full-sync-message]');
    var $title = $('[data-olama-progress-title]');
    var $percent = $('[data-olama-progress-percent]');
    var $bar = $('[data-olama-full-sync-bar]');
    var $bridge = $('[data-olama-bridge-status]');
    var $jobControl = $('[data-olama-job-control]');
    var phaseSets = {
        fast: ['fast_sync', 'employees', 'academic', 'transportation', 'validation'],
        complete: ['families', 'students', 'employees', 'academic', 'transportation', 'validation'],
        family_pipeline: ['families', 'students', 'validation']
    };

    function request(action, data) {
        return $.post(OlamaOracleFullSync.ajaxUrl, $.extend({
            action: action,
            nonce: OlamaOracleFullSync.nonce
        }, data || {}));
    }

    function setBusy(busy) {
        state.jobBusy = busy;
        var disabled = busy || !state.bridgeConnected;
        $startButtons.prop('disabled', disabled);
        $oneButton.prop('disabled', disabled);
        $('.olama-oracle-sync-choice').toggleClass('is-busy', busy);
    }

    function renderBridge(connected, configured, message) {
        state.bridgeConnected = connected;
        $bridge.removeClass('is-ready is-offline is-checking is-missing');
        if (!configured) {
            $bridge.addClass('is-missing');
            $('[data-olama-bridge-title]').text('إعداد الاتصال غير مكتمل');
        } else if (connected) {
            $bridge.addClass('is-ready');
            $('[data-olama-bridge-title]').text('Oracle Bridge متصل الآن');
        } else {
            $bridge.addClass('is-offline');
            $('[data-olama-bridge-title]').text('Oracle Bridge غير متصل');
        }
        $('[data-olama-bridge-message]').text(message || 'تعذر التحقق من الاتصال.');
        setBusy(state.jobBusy);
        if (state.lastJob) {
            renderJobControl(state.lastJob);
        }
    }

    function checkBridge() {
        if (state.checkingBridge || !OlamaOracleFullSync.configured) {
            if (!OlamaOracleFullSync.configured) {
                renderBridge(false, false, 'أدخل رابط Oracle Bridge ومفتاح API من صفحة الإعدادات.');
            }
            return;
        }

        state.checkingBridge = true;
        if (!state.bridgeConnected) {
            $bridge.removeClass('is-ready is-offline').addClass('is-checking');
            $('[data-olama-bridge-title]').text('جاري فحص Oracle Bridge...');
        }
        request('olama_oracle_bridge_status').done(function(response) {
            var data = response && response.data ? response.data : {};
            renderBridge(!!data.connected, data.configured !== false, data.connected
                ? 'تم التحقق من Bridge وقاعدة بيانات Oracle.'
                : (data.message || 'تعذر الوصول إلى Oracle Bridge.'));
        }).fail(function() {
            renderBridge(false, true, 'تعذر الوصول إلى WordPress أثناء فحص Oracle Bridge.');
        }).always(function() {
            state.checkingBridge = false;
            window.setTimeout(checkBridge, 30000);
        });
    }

    function setPhase(current, status, scope) {
        var phases = phaseSets[scope] || phaseSets.complete;
        var currentIndex = phases.indexOf(current);
        $('[data-phase]').removeClass('is-active is-complete').attr('hidden', true);
        phases.forEach(function(phase, index) {
            var $phase = $('[data-phase="' + phase + '"]');
            $phase.removeAttr('hidden');
            if (status === 'completed' || status === 'completed_with_errors' || index < currentIndex) {
                $phase.addClass('is-complete');
            } else if (index === currentIndex) {
                $phase.addClass('is-active');
            }
        });
    }

    function renderJobControl(job) {
        if (!$jobControl.length || !job || job.done || ['queued', 'running', 'paused'].indexOf(job.status) === -1) {
            $jobControl.attr('hidden', true).prop('disabled', true);
            return;
        }
        var paused = job.status === 'paused';
        $jobControl.removeAttr('hidden')
            .attr('data-command', paused ? 'resume' : 'pause')
            .text(paused ? 'استئناف المزامنة' : 'إيقاف مؤقت')
            .prop('disabled', paused && !state.bridgeConnected);
    }

    function renderJob(job) {
        var progress = Math.max(0, Math.min(100, parseFloat(job.progress_percentage) || 0));
        var counts = job.counts || {};
        state.lastJob = job;
        state.jobId = parseInt(job.id, 10) || state.jobId;
        $card.attr('data-active-job', state.jobId);
        $percent.text(Math.round(progress) + '%');
        $bar.css('width', progress + '%');
        $message.text(job.error_summary || job.message || 'جاري تنفيذ المزامنة...');
        $('[data-olama-job-count]').each(function() {
            var key = $(this).attr('data-olama-job-count');
            $(this).text(parseInt(counts[key], 10) || 0);
        });
        setPhase(job.current_phase, job.status, job.scope);

        $card.removeClass('has-error is-complete is-running is-paused');
        if (job.status === 'failed') {
            $title.text('توقفت العملية #' + job.id);
            $card.addClass('has-error');
        } else if (job.status === 'paused') {
            $title.text('العملية #' + job.id + ' متوقفة مؤقتاً');
            $card.addClass('is-paused');
        } else if (job.done) {
            $title.text('اكتملت العملية #' + job.id);
            $card.addClass('is-complete');
        } else {
            $title.text('العملية #' + job.id + ' قيد التشغيل');
            $card.addClass('is-running');
        }
        setBusy(!job.done);
        renderJobControl(job);
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
                window.setTimeout(pollJob, 10000);
            }
        }).fail(function() {
            $message.text('تعذر الاتصال مؤقتاً. ستتم إعادة المحاولة تلقائياً.');
            window.setTimeout(pollJob, 10000);
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
            checkBridge();
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

    $jobControl.on('click', function() {
        if (!state.jobId) {
            return;
        }
        var command = String($jobControl.attr('data-command') || '');
        if (command !== 'pause' && command !== 'resume') {
            return;
        }

        $jobControl.prop('disabled', true);
        $message.text(command === 'pause'
            ? 'سيتم إيقاف العملية بعد اكتمال الدفعة الحالية...'
            : 'جاري التحقق من الاتصال واستئناف العملية...');
        request('olama_oracle_control_sync_job', {job_id: state.jobId, command: command}).done(function(response) {
            if (!response || !response.success) {
                $message.text(response && response.data && response.data.message ? response.data.message : 'تعذر تغيير حالة العملية.');
                renderJobControl(state.lastJob);
                return;
            }
            renderJob(response.data);
            if (command === 'resume') {
                pollJob();
            }
        }).fail(function(xhr) {
            var data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
            $message.text(data.message || 'تعذر تغيير حالة العملية.');
            renderJobControl(state.lastJob);
            if (command === 'resume') {
                checkBridge();
            }
        });
    });

    if (state.jobId) {
        setBusy(true);
        pollJob();
    }
    checkBridge();
})(jQuery);
