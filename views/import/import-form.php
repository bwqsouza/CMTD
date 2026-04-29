<?php
/** @var \yii\web\View $this */
/** @var array $importHistory */
?>

<style>
.import-card {
    background: #fff;
    border: 1px solid #dde3ea;
    border-radius: 6px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.import-card h4 {
    margin-top: 0;
    font-size: 17px;
    font-weight: 600;
    color: #2c3e50;
}
.tip-box {
    background: #e8f4fd;
    border-left: 4px solid #3498db;
    border-radius: 4px;
    padding: 10px 14px;
    margin-bottom: 18px;
    font-size: 13.5px;
    color: #1a5276;
}
.tip-box strong { color: #1a5276; }
.progress { height: 20px; border-radius: 4px; margin-bottom: 6px; background-color: #e9ecef; }
.progress-bar { line-height: 20px; font-size: 12px; font-weight: 600; transition: width 0.4s ease; }
.progress-label { font-size: 12px; color: #666; margin-bottom: 4px; }
.history-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 14px;
}
.history-item:last-child { border-bottom: none; }
.history-item .badge-ok {
    color: #27ae60;
    font-weight: 600;
    margin-right: 6px;
}
.history-item .at { color: #999; font-size: 12px; }
#progress-section { display: none; }
#result-alert   { display: none; }
</style>

<div class="container" style="max-width:680px; margin-top:30px;">

    <!-- ── Formulário ── -->
    <div class="import-card">
        <h4><span class="glyphicon glyphicon-download-alt"></span> Importar Dados Históricos da B3</h4>

        <p style="color:#555; font-size:14px; margin-bottom:14px;">
            Os dados são baixados diretamente do portal da B3 (COTAHIST). Selecione o tipo e o
            período desejado. O processo pode levar alguns minutos dependendo do tamanho do arquivo.
        </p>

        <div class="tip-box">
            <strong>Dica:</strong> Para rodar previsões, importe ao menos <strong>1 ano completo</strong>.
            Após importar, o sistema estará pronto para uso.
        </div>

        <div class="form-group">
            <label for="import-type">Tipo de importação</label>
            <select id="import-type" class="form-control">
                <option value="year">Anual (recomendado)</option>
                <option value="day">Diário</option>
            </select>
        </div>

        <div class="form-group" id="field-year">
            <label for="import-year">Ano</label>
            <input type="number" id="import-year" class="form-control"
                   value="<?= date('Y') ?>" min="1995" max="<?= date('Y') ?>"
                   placeholder="Ex: 2020, 2021, 2022">
            <span class="help-block" style="font-size:12px;">Informe o ano que deseja importar (ex: 2020, 2021, 2022).</span>
        </div>

        <div class="form-group" id="field-day" style="display:none;">
            <label for="import-day">Data</label>
            <input type="text" id="import-day" class="form-control"
                   placeholder="Ex: 02012020 (ddmmaaaa)">
            <span class="help-block" style="font-size:12px;">Informe a data no formato ddmmaaaa.</span>
        </div>

        <button id="btn-import" class="btn btn-primary btn-block" style="margin-top:8px;">
            <span class="glyphicon glyphicon-cloud-download"></span> Iniciar Importação
        </button>
    </div>

    <!-- ── Progresso ── -->
    <div class="import-card" id="progress-section">
        <h4><span class="glyphicon glyphicon-refresh"></span> Progresso</h4>

        <div class="progress-label">Download</div>
        <div class="progress">
            <div id="bar-download" class="progress-bar progress-bar-info progress-bar-striped active"
                 role="progressbar" style="width:0%">0%</div>
        </div>

        <div class="progress-label" style="margin-top:10px;">Importação para banco</div>
        <div class="progress">
            <div id="bar-import" class="progress-bar progress-bar-success progress-bar-striped active"
                 role="progressbar" style="width:0%">0%</div>
        </div>

        <p id="progress-msg" style="font-size:13px; color:#555; margin-top:8px;"></p>
    </div>

    <!-- ── Resultado ── -->
    <div id="result-alert" class="alert" role="alert" style="border-radius:6px;"></div>

    <!-- ── Histórico desta sessão ── -->
    <div class="import-card" id="history-card">
        <h4 style="color:#3498db;">Importações desta sessão</h4>

        <div id="history-list">
            <?php if (empty($importHistory)): ?>
                <p style="color:#aaa; font-size:14px;" id="no-history-msg">
                    Nenhuma importação realizada nesta sessão.
                </p>
            <?php else: ?>
                <?php foreach ($importHistory as $item): ?>
                    <div class="history-item">
                        <span>
                            <span class="badge-ok">&#10004;</span>
                            <strong><?= htmlspecialchars($item['label']) ?></strong>
                            &mdash; <?= number_format($item['count'], 0, ',', '.') ?> registros
                        </span>
                        <span class="at"><?= htmlspecialchars($item['at']) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    var pollInterval = null;

    // Toggle day/year field
    document.getElementById('import-type').addEventListener('change', function () {
        var isDay = this.value === 'day';
        document.getElementById('field-year').style.display = isDay ? 'none' : '';
        document.getElementById('field-day').style.display  = isDay ? ''     : 'none';
    });

    document.getElementById('btn-import').addEventListener('click', function () {
        var type = document.getElementById('import-type').value;
        var startDate;

        if (type === 'year') {
            startDate = document.getElementById('import-year').value.trim();
            if (!startDate || isNaN(startDate) || startDate.length !== 4) {
                showAlert('danger', 'Informe um ano válido (ex: 2022).');
                return;
            }
        } else {
            startDate = document.getElementById('import-day').value.trim();
            if (!startDate || startDate.length !== 8) {
                showAlert('danger', 'Informe a data no formato ddmmaaaa (ex: 02012022).');
                return;
            }
        }

        var jobId = generateUUID();
        startImport(type, startDate, jobId);
    });

    function startImport(type, startDate, jobId) {
        setFormEnabled(false);
        showProgress(true);
        showAlert('', '');

        updateBars(0, 0, 'Iniciando...');

        // Fire the import request (it runs long — we poll separately)
        fetch('import-data?type=' + encodeURIComponent(type) +
              '&startDate=' + encodeURIComponent(startDate) +
              '&jobId=' + encodeURIComponent(jobId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                stopPolling();
                if (data.status === 'success') {
                    updateBars(100, 100, data.message);
                    showAlert('success', '<strong>Sucesso!</strong> ' + data.message);
                    addHistoryItem(startDate, type, data.count);
                } else {
                    showAlert('danger', '<strong>Erro:</strong> ' + data.message);
                }
                setFormEnabled(true);
            })
            .catch(function (err) {
                stopPolling();
                showAlert('danger', '<strong>Erro de comunicação:</strong> ' + err.message);
                setFormEnabled(true);
            });

        // Start polling for progress
        pollInterval = setInterval(function () {
            fetch('import-progress?jobId=' + encodeURIComponent(jobId))
                .then(function (r) { return r.json(); })
                .then(function (p) {
                    if (p.download_pct !== undefined) {
                        updateBars(p.download_pct, p.import_pct, p.message || '');
                    }
                })
                .catch(function () { /* ignore poll errors */ });
        }, 600);
    }

    function updateBars(dlPct, impPct, msg) {
        setBar('bar-download', dlPct);
        setBar('bar-import', impPct);
        document.getElementById('progress-msg').textContent = msg || '';
    }

    function setBar(id, pct) {
        var bar = document.getElementById(id);
        bar.style.width = pct + '%';
        bar.textContent = pct + '%';
        if (pct >= 100) {
            bar.classList.remove('active');
        } else {
            bar.classList.add('active');
        }
    }

    function showProgress(show) {
        document.getElementById('progress-section').style.display = show ? 'block' : 'none';
    }

    function showAlert(type, html) {
        var el = document.getElementById('result-alert');
        if (!type) { el.style.display = 'none'; return; }
        el.className = 'alert alert-' + type;
        el.innerHTML = html;
        el.style.display = '';
    }

    function setFormEnabled(enabled) {
        document.getElementById('btn-import').disabled = !enabled;
        document.getElementById('import-type').disabled = !enabled;
        document.getElementById('import-year').disabled = !enabled;
        document.getElementById('import-day').disabled  = !enabled;
    }

    function stopPolling() {
        if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
    }

    function addHistoryItem(startDate, type, count) {
        var noMsg = document.getElementById('no-history-msg');
        if (noMsg) noMsg.remove();

        var label = (type === 'year') ? 'Ano ' + startDate : 'Dia ' + startDate;
        var now   = new Date();
        var at    = pad(now.getDate()) + '/' + pad(now.getMonth() + 1) + '/' + now.getFullYear() +
                    ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes());
        var fmt   = count.toLocaleString('pt-BR');

        var div = document.createElement('div');
        div.className = 'history-item';
        div.innerHTML = '<span><span class="badge-ok">&#10004;</span><strong>' +
                        label + '</strong> &mdash; ' + fmt + ' registros</span>' +
                        '<span class="at">' + at + '</span>';

        var list = document.getElementById('history-list');
        list.insertBefore(div, list.firstChild);
    }

    function pad(n) { return n < 10 ? '0' + n : n; }

    function generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }
}());
</script>
