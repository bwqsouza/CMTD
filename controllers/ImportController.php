<?php

namespace app\controllers;

use app\models\Paper;
use DateTime;
use Exception;
use yii\base\Controller;
use ZipArchive;
use Yii;

class ImportController extends Controller
{
    private function writeProgress(string $file, array $data): void
    {
        $current = [];
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            if ($raw) {
                $current = json_decode($raw, true) ?? [];
            }
        }
        file_put_contents($file, json_encode(array_merge($current, $data)), LOCK_EX);
    }

    public function actionImportForm()
    {
        $this->layout = 'navbar';
        $importHistory = Yii::$app->session->get('import_history', []);
        return $this->render('import-form', ['importHistory' => $importHistory]);
    }

    public function actionImportProgress()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        Yii::$app->session->close(); // não precisa de sessão, libera o lock imediatamente

        $jobId = Yii::$app->request->get('jobId', '');
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $jobId)) {
            return ['status' => 'error', 'message' => 'Job ID inválido.'];
        }

        $progressFile = sys_get_temp_dir() . '/import_progress_' . $jobId . '.json';

        if (!file_exists($progressFile)) {
            return ['download_pct' => 0, 'import_pct' => 0, 'status' => 'waiting', 'message' => 'Aguardando...'];
        }

        $data = json_decode(file_get_contents($progressFile), true);
        return $data ?: ['status' => 'error', 'message' => 'Erro ao ler progresso.'];
    }

    public function actionImportData()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '-1');

        $startDate = Yii::$app->request->get('startDate');
        $type      = Yii::$app->request->get('type', 'year');
        $jobId     = Yii::$app->request->get('jobId', '');

        $progressFile = (preg_match('/^[a-f0-9\-]{36}$/', $jobId))
            ? sys_get_temp_dir() . '/import_progress_' . $jobId . '.json'
            : null;

        Yii::info("[IMPORT] Iniciando importação. tipo=$type data=$startDate job=$jobId", 'import');

        if (!$startDate) {
            Yii::error('[IMPORT] Campo startDate ausente.', 'import');
            return ['status' => 'error', 'message' => 'O campo "Ano" é obrigatório.'];
        }

        $typeFromDownload = ($type === 'day') ? 'D' : 'A';
        $format           = ($type === 'day') ? 'dmY' : 'Y';
        $parsed           = DateTime::createFromFormat($format, $startDate);

        if (!$parsed) {
            Yii::error("[IMPORT] Formato de data inválido: $startDate", 'import');
            return ['status' => 'error', 'message' => "Formato de data inválido: \"$startDate\"."];
        }

        $begin = $parsed->format($format);

        try {
            if ($progressFile) {
                $this->writeProgress($progressFile, [
                    'download_pct' => 0,
                    'import_pct'   => 0,
                    'status'       => 'downloading',
                    'message'      => 'Iniciando download da B3...',
                ]);
            }

            // Libera o session lock para que as requisições de polling não fiquem bloqueadas
            Yii::$app->session->close();

            $this->downloadData($begin, $typeFromDownload, $progressFile);
            $this->extractData($begin, $typeFromDownload);

            if ($progressFile) {
                $this->writeProgress($progressFile, [
                    'download_pct' => 100,
                    'import_pct'   => 0,
                    'status'       => 'importing',
                    'message'      => 'Processando registros...',
                ]);
            }

            $count = $this->parseDataAndSaveInDatabase($begin, $typeFromDownload, $progressFile);

            $label   = ($typeFromDownload === 'A') ? "Ano $begin" : "Dia $begin";
            $history = Yii::$app->session->get('import_history', []);
            array_unshift($history, [
                'label' => $label,
                'count' => $count,
                'at'    => date('d/m/Y H:i'),
            ]);
            Yii::$app->session->set('import_history', $history);

            if ($progressFile) {
                $this->writeProgress($progressFile, [
                    'download_pct' => 100,
                    'import_pct'   => 100,
                    'status'       => 'done',
                    'label'        => $label,
                    'count'        => $count,
                    'at'           => date('d/m/Y H:i'),
                    'message'      => number_format($count, 0, ',', '.') . ' registros importados com sucesso.',
                ]);
            }

            Yii::info("[IMPORT] Concluído. count=$count job=$jobId", 'import');

            return [
                'status'  => 'success',
                'count'   => $count,
                'message' => number_format($count, 0, ',', '.') . ' registros importados com sucesso.',
            ];
        } catch (\Exception $e) {
            Yii::error('[IMPORT] Erro: ' . $e->getMessage() . " job=$jobId", 'import');

            if ($progressFile) {
                $this->writeProgress($progressFile, [
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ]);
            }

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function downloadData(string $date, string $type, ?string $progressFile = null): void
    {
        $dir = Yii::getAlias('@app') . '/assets/COTAHIST/ZIP';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $zipName  = 'COTAHIST_' . $type . $date . '.ZIP';
        $filePath = $dir . '/' . $zipName;
        $url      = 'https://bvmf.bmfbovespa.com.br/InstDados/SerHist/' . $zipName;

        Yii::info("[IMPORT] Baixando $url", 'import');

        $fh = fopen($filePath, 'w');
        if (!$fh) {
            throw new \Exception("Não foi possível criar o arquivo temporário: $filePath");
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FILE, $fh);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        if ($progressFile) {
            $pf = $progressFile;
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($pf) {
                if ($dlTotal > 0) {
                    $pct = min(99, (int)(($dlNow / $dlTotal) * 100));
                    $this->writeProgress($pf, [
                        'download_pct' => $pct,
                        'message'      => "Baixando arquivo... {$pct}%",
                    ]);
                }
                return 0;
            });
        }

        $ok       = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if (!$ok || $curlErr) {
            @unlink($filePath);
            Yii::error("[IMPORT] curl error: $curlErr url=$url", 'import');
            throw new \Exception("Erro ao baixar arquivo da B3: $curlErr");
        }

        if ($httpCode !== 200) {
            @unlink($filePath);
            Yii::error("[IMPORT] HTTP $httpCode para $url", 'import');
            throw new \Exception("Arquivo não encontrado na B3 (HTTP $httpCode). Verifique o ano/data informado.");
        }

        Yii::info("[IMPORT] Download concluído: $filePath (" . round(filesize($filePath) / 1024 / 1024, 2) . " MB)", 'import');
    }

    public function extractData(string $date, string $type): void
    {
        $dir      = Yii::getAlias('@app') . '/assets/COTAHIST/ZIP';
        $zipName  = 'COTAHIST_' . $type . $date . '.ZIP';
        $filePath = $dir . '/' . $zipName;

        Yii::info("[IMPORT] Extraindo $filePath", 'import');

        $zip = new ZipArchive();
        $res = $zip->open($filePath);
        if ($res === true) {
            $zip->extractTo($dir);
            $zip->close();
            unlink($filePath);
            Yii::info('[IMPORT] ZIP extraído com sucesso.', 'import');
        } else {
            Yii::error("[IMPORT] Falha ao abrir ZIP (código: $res): $filePath", 'import');
            throw new \Exception("Falha ao abrir ZIP (código: $res). O arquivo pode estar corrompido.");
        }
    }

    public function parseDataAndSaveInDatabase(string $date, string $type, ?string $progressFile = null): int
    {
        $typeWithSeparator = ($type === 'D') ? '_D' : '_A';
        $txtPath = Yii::getAlias('@app') . '/assets/COTAHIST/ZIP/COTAHIST' . $typeWithSeparator . $date . '.TXT';

        Yii::info("[IMPORT] Iniciando parse: $txtPath", 'import');

        if (!file_exists($txtPath)) {
            Yii::error("[IMPORT] Arquivo TXT não encontrado: $txtPath", 'import');
            throw new \Exception("Arquivo não encontrado após extração. Verifique se o ano informado possui dados disponíveis na B3.");
        }

        $totalLines = max(1, $this->countLines($txtPath) - 2);
        Yii::info("[IMPORT] Total de linhas (estimado): $totalLines", 'import');

        $actions = $this->getActionsList();
        $file    = fopen($txtPath, 'r');
        $count   = 0;
        $lineNum = 0;
        $errors  = 0;

        if ($file) {
            fgets($file); // pula cabeçalho
            while (($line = fgets($file)) !== false) {
                if (substr($line, 0, 10) === '99COTAHIST') {
                    break;
                }
                $lineNum++;

                if ($progressFile && ($lineNum % 500 === 0)) {
                    $pct = min(99, (int)(($lineNum / $totalLines) * 100));
                    $this->writeProgress($progressFile, [
                        'import_pct' => $pct,
                        'message'    => "Importando registros... {$pct}% ({$count} salvos)",
                    ]);
                }

                try {
                    $line = mb_convert_encoding($line, 'US-ASCII', 'UTF-8');

                    if (in_array(trim(substr($line, 12, 12)), $actions)) {
                        $paper = new Paper();

                        $dateTime = \DateTime::createFromFormat('YmdHis', substr($line, 2, 8) . '000000')
                            ->modify('+1 day');

                        $paper->date       = new \MongoDB\BSON\UTCDateTime($dateTime);
                        $paper->codbdi     = substr($line, 10, 2);
                        $paper->codneg     = trim(substr($line, 12, 12));
                        $paper->tpmerc     = substr($line, 24, 3);
                        $paper->nomres     = trim(substr($line, 27, 12));
                        $paper->especi     = substr($line, 39, 10);
                        $paper->prazot     = substr($line, 49, 3);
                        $paper->modref     = trim(substr($line, 52, 4));
                        $paper->preab      = (float) substr_replace(substr($line, 56, 13), '.', 11, 0);
                        $paper->premax     = (float) substr_replace(substr($line, 69, 13), '.', 11, 0);
                        $paper->premin     = (float) substr_replace(substr($line, 82, 13), '.', 11, 0);
                        $paper->premed     = (float) substr_replace(substr($line, 95, 13), '.', 11, 0);
                        $paper->preult     = (float) substr_replace(substr($line, 108, 13), '.', 11, 0);
                        $paper->preofc     = (float) substr_replace(substr($line, 121, 13), '.', 11, 0);
                        $paper->preofv     = (float) substr_replace(substr($line, 134, 13), '.', 11, 0);
                        $paper->totneg     = substr($line, 147, 5);
                        $paper->quatot     = substr($line, 152, 18);
                        $paper->voltot     = substr($line, 170, 16);
                        $paper->preexe     = substr($line, 188, 11);
                        $paper->indopc     = substr($line, 201, 1);
                        $paper->datven     = substr($line, 202, 8);
                        $paper->fatcot     = substr($line, 210, 7);
                        $paper->ptoexe     = substr($line, 217, 7);
                        $paper->codisi     = substr($line, 230, 12);
                        $paper->dismes     = substr($line, 242, 3);
                        $paper->created_at = date('Y-m-d H:i:s');

                        if ($paper->save()) {
                            $count++;
                        } else {
                            $errors++;
                            Yii::warning('[IMPORT] Falha ao salvar ' . trim(substr($line, 12, 12)) . ': ' . json_encode($paper->errors), 'import');
                        }
                    }
                } catch (Exception $e) {
                    $errors++;
                    Yii::warning("[IMPORT] Erro na linha $lineNum: " . $e->getMessage(), 'import');
                }
            }
            fclose($file);
        }

        Yii::info("[IMPORT] Parse finalizado. salvos=$count erros=$errors linhas=$lineNum", 'import');
        return $count;
    }

    private function countLines(string $filePath): int
    {
        $count = 0;
        $fh    = fopen($filePath, 'r');
        if ($fh) {
            while (!feof($fh)) {
                fgets($fh);
                $count++;
            }
            fclose($fh);
        }
        return $count;
    }

    private function getActionsList(): array
    {
        return [
            'ABCB4', 'ABCP11', 'AFLT3', 'AGRO3', 'ALPA3', 'ALPA4', 'AMAR3', 'ANCR11B',
            'BAHI3', 'BALM4', 'BAUH4', 'BAZA3', 'BBAS3', 'BBDC3', 'BBDC4', 'BBFI11B',
            'BBRK3', 'BDLL4', 'BEEF3', 'BEES3', 'BEES4', 'BGIP3', 'BGIP4', 'BIOM3',
            'BMEB3', 'BMEB4', 'BMIN3', 'BMIN4', 'BMKS3', 'BNBR3', 'BOBR4', 'BOVA11',
            'BRAP3', 'BRAP4', 'BRAX11', 'BRFS3', 'BRGE11', 'BRGE12', 'BRGE3', 'BRGE6',
            'BRGE8', 'BRIV3', 'BRIV4', 'BRKM3', 'BRKM5', 'BRKM6', 'BRML3', 'BRPR3',
            'BRSR3', 'BRSR5', 'BRSR6', 'BSLI4', 'BTOW3', 'BTTL3', 'CARD3', 'CBEE3',
            'CCPR3', 'CCRO3', 'CEBR3', 'CEBR5', 'CEBR6', 'CEDO3', 'CEDO4', 'CEEB3',
            'CEEB5', 'CEED3', 'CEED4', 'CEGR3', 'CEPE5', 'CEPE6', 'CESP3', 'CESP5',
            'CESP6', 'CGAS3', 'CGAS5', 'CGRA3', 'CGRA4', 'CIEL3', 'CLSC3', 'CMIG3',
            'CMIG4', 'COCE3', 'COCE5', 'CPFE3', 'CPLE3', 'CPLE6', 'CRDE3', 'CRIV3',
            'CRIV4', 'CSAB3', 'CSAB4', 'CSAN3', 'CSMG3', 'CSNA3', 'CSRN3', 'CSRN5',
            'CSRN6', 'CTKA3', 'CTKA4', 'CTNM3', 'CTNM4', 'CTSA3', 'CTSA4', 'CXCE11B',
            'CYRE3', 'DASA3', 'DIRR3', 'DOHL3', 'DOHL4', 'DTCY3', 'DTEX3', 'EALT4',
            'ECOR3', 'ECPR3', 'EDFO11B', 'EEEL3', 'EEEL4', 'EKTR4', 'ELET3', 'ELET5',
            'ELET6', 'EMAE4', 'EMBR3', 'ENBR3', 'ENGI11', 'ENGI3', 'ENGI4', 'EQTL3',
            'ESTR4', 'ETER3', 'EUCA4', 'EURO11', 'EVEN3', 'EZTC3', 'FAMB11B', 'FESA3',
            'FESA4', 'FHER3', 'FIIP11B', 'FLMA11', 'FLRY3', 'FMOF11', 'FNAM11', 'FNOR11',
            'FPAB11', 'FRAS3', 'FRIO3', 'FSPE11', 'FSRF11', 'FSTU11', 'GEPA3', 'GEPA4',
            'GFSA3', 'GGBR3', 'GGBR4', 'GOAU3', 'GOAU4', 'GOLL4', 'GPAR3', 'GPCP3',
            'GRND3', 'GSHP3', 'GUAR3', 'HAGA3', 'HAGA4', 'HBOR3', 'HBTS5', 'HETA4',
            'HGBS11', 'HGRE11', 'HGTX3', 'HOOT4', 'HYPE3', 'IBOV11', 'IGBR3', 'IGTA3',
            'INEP3', 'INEP4', 'ITSA3', 'ITSA4', 'ITUB3', 'ITUB4', 'JBDU3', 'JBDU4',
            'JBSS3', 'JFEN3', 'JHSF3', 'JOPA3', 'JOPA4', 'JSLG3', 'KEPL3', 'KLBN3',
            'KLBN4', 'KNRI11', 'LAME3', 'LAME4', 'LIGT3', 'LIPR3', 'LLIS3', 'LOGN3',
            'LPSB3', 'LREN3', 'LUPA3', 'MAPT4', 'MDIA3', 'MERC3', 'MERC4', 'MGEL4',
            'MILS3', 'MMXM3', 'MNDL3', 'MNPR3', 'MOAR3', 'MRFG3', 'MRVE3', 'MSPA3',
            'MSPA4', 'MTIG4', 'MTSA4', 'MULT3', 'MWET4', 'MYPK3', 'ODPV3', 'OSXB3',
            'PABY11', 'PATI3', 'PATI4', 'PDGR3', 'PEAB3', 'PEAB4', 'PETR3', 'PETR4',
            'PFRM3', 'PIBB11', 'PINE4', 'PLAS3', 'PMAM3', 'PNVL3', 'PNVL4', 'POMO3',
            'POMO4', 'POSI3', 'PQDP11', 'PRSV11', 'PSSA3', 'PTBL3', 'PTNT3', 'PTNT4',
            'RANI3', 'RAPT3', 'RAPT4', 'RBDS11', 'RBRD11', 'RCSL3', 'RCSL4', 'RDNI3',
            'REDE3', 'RENT3', 'RNEW11', 'ROMI3', 'RPAD3', 'RPAD5', 'RPAD6', 'RPMG3',
            'RSID3', 'SANB11', 'SANB3', 'SANB4', 'SAPR4', 'SBSP3', 'SCAR3', 'SGPS3',
            'SHPH11', 'SHUL4', 'SLCE3', 'SLED3', 'SLED4', 'SMAL11', 'SMTO3', 'SNSY5',
            'SOND5', 'SOND6', 'SULA11', 'TCNO3', 'TCNO4', 'TCSA3', 'TEKA3', 'TEKA4',
            'TELB3', 'TELB4', 'TGMA3', 'TKNO4', 'TOTS3', 'TPIS3', 'TRIS3', 'TRPL3',
            'TRPL4', 'TUPY3', 'TXRX3', 'TXRX4', 'UGPA3', 'UNIP3', 'UNIP5', 'UNIP6',
            'USIM3', 'USIM5', 'USIM6', 'VALE3', 'VLID3', 'VULC3', 'WEGE3', 'WHRL3',
            'WHRL4',
        ];
    }
}
