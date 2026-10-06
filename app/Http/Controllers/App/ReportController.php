<?php

namespace App\Http\Controllers\App;

use App\Application\Reports\ReportBuilder;
use App\Application\Reports\ReportPdf;
use App\Application\Shared\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const REPORTS = [
        'faturamento' => 'Faturamento',
        'clientes' => 'Clientes',
        'barbeiros' => 'Barbeiros',
        'comissoes' => 'Comissões',
    ];

    public function show(
        Request $request,
        string $report,
        ReportBuilder $reportBuilder,
        TenantContext $tenantContext,
    ): View {
        $this->authorize('reports.view');
        abort_unless(isset(self::REPORTS[$report]), 404);

        [$from, $to] = $this->dateRange($request);
        $tenantId = $tenantContext->id();
        abort_if($tenantId === null, 403);
        $data = $reportBuilder->build($report, $from, $to, (int) $tenantId);

        return view('app.reports.show', [
            'reportKey' => $report,
            'reportName' => self::REPORTS[$report],
            'report' => $data,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function export(
        Request $request,
        string $report,
        string $format,
        ReportBuilder $reportBuilder,
        ReportPdf $reportPdf,
        TenantContext $tenantContext,
    ): Response|StreamedResponse {
        $this->authorize('reports.view');
        abort_unless(isset(self::REPORTS[$report]) && in_array($format, ['csv', 'pdf'], true), 404);

        [$from, $to] = $this->dateRange($request);
        $tenantId = $tenantContext->id();
        abort_if($tenantId === null, 403);
        $data = $reportBuilder->build($report, $from, $to, (int) $tenantId);
        $filename = $report.'-'.$from.'-'.$to.'.'.$format;

        if ($format === 'pdf') {
            return response($reportPdf->render($data, $from, $to), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }

        return ResponseFactory::streamDownload(function () use ($data, $from, $to): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [$data['title']], ';', '"', '');
            fputcsv($output, ['Período', $from, $to], ';', '"', '');
            foreach ($data['summary'] as $label => $value) {
                fputcsv($output, [$label, $value], ';', '"', '');
            }
            fputcsv($output, [], ';', '"', '');
            fputcsv($output, $data['columns'], ';', '"', '');
            foreach ($data['rows'] as $row) {
                fputcsv($output, array_map($this->safeCsvCell(...), $row), ';', '"', '');
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function safeCsvCell(string $value): string
    {
        return preg_match('/^[\s]*[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * @return array{string, string}
     */
    private function dateRange(Request $request): array
    {
        $today = now();
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $from = $filters['from'] ?? $today->copy()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? $today->toDateString();

        if ($to < $from) {
            throw ValidationException::withMessages([
                'to' => 'A data final deve ser igual ou posterior à data inicial.',
            ]);
        }

        return [$from, $to];
    }
}
