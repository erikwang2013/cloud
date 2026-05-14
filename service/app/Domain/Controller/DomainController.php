<?php
namespace App\Domain\Controller;

use App\Domain\Service\DomainService;
use Common\Helper\Response;

class DomainController
{
    private DomainService $service;

    public function __construct()
    {
        $this->service = new DomainService();
    }

    public function check($request, string $domain, string $tld)
    {
        $result = $this->service->checkAvailability($domain, $tld);
        return json(Response::success($result));
    }

    public function tlds($request)
    {
        return json(Response::success($this->service->getTlds()));
    }

    public function listRecords($request, string $domain)
    {
        $records = $this->service->listDnsRecords($request->userId, $domain);
        return json(Response::success($records));
    }

    public function addRecord($request, string $domain)
    {
        $data = $request->all();
        $record = $this->service->addDnsRecord($request->userId, $domain, $data);
        return json(Response::success($record));
    }

    public function deleteRecord($request, string $domain, int $id)
    {
        $this->service->deleteDnsRecord($request->userId, $domain, $id);
        return json(Response::success());
    }
}
