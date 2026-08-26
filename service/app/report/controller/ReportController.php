<?php
namespace App\report\controller;

use App\report\service\ReportService;
use Common\helper\Response;

class ReportController
{
    private ReportService $service;

    public function __construct()
    {
        $this->service = new ReportService();
    }

    public function revenue($request)
    {
        $startDate = $request->input('start_date', date('Y-m-01'));
        $endDate   = $request->input('end_date', date('Y-m-d'));
        $report = $this->service->revenueReport($startDate, $endDate);
        return json(Response::success($report));
    }

    public function supplier($request)
    {
        $supplierId = $request->input('supplier_id');
        $startDate  = $request->input('start_date', date('Y-m-01'));
        $endDate    = $request->input('end_date', date('Y-m-d'));
        $report = $this->service->supplierReport($supplierId, $startDate, $endDate);
        return json(Response::success($report));
    }

    public function byRegion($request)
    {
        $startDate = $request->input('start_date', date('Y-m-01'));
        $endDate   = $request->input('end_date', date('Y-m-d'));
        $report = $this->service->salesByRegion($startDate, $endDate);
        return json(Response::success($report));
    }
}
