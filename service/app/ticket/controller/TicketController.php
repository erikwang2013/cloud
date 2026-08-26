<?php
namespace App\ticket\controller;

use App\ticket\service\TicketService;
use App\ticket\model\Ticket;
use Common\helper\Response;
use Common\helper\Validator;

class TicketController
{
    private TicketService $service;

    public function __construct()
    {
        $this->service = new TicketService();
    }

    public function create($request)
    {
        $data = $request->only(['resource_id', 'category', 'priority', 'title', 'content']);

        if ($missing = Validator::required($data, ['category', 'title', 'content'])) {
            return json(Response::error(422, "Missing required field: {$missing}"));
        }
        if (!empty($data['priority']) && !in_array($data['priority'], ['low', 'normal', 'high', 'urgent'], true)) {
            return json(Response::error(422, 'Invalid priority'));
        }

        $ticket = $this->service->create($request->userId, $data);
        return json(Response::success($ticket, 'Ticket created'));
    }

    public function myTickets($request)
    {
        $tickets = Ticket::where('user_id', $request->userId)
            ->with(['latestMessage'])
            ->orderBy('updated_at', 'desc')
            ->paginate(20);
        return json(Response::paginated($tickets->items(), $tickets->total(), $request->input('page', 1), 20));
    }

    public function show($request, int $id)
    {
        $ticket = Ticket::with('messages')->findOrFail($id);
        if ($request->userRole === 'user' && $ticket->user_id !== $request->userId) {
            return json(Response::error(403, 'Forbidden'));
        }
        return json(Response::success($ticket));
    }

    public function reply($request, int $id)
    {
        $senderType = in_array($request->userRole, ['admin', 'support', 'super_admin']) ? 'staff' : 'user';
        $msg = $this->service->reply($id, $request->userId, $senderType, $request->input('content'));
        return json(Response::success($msg, 'Reply sent'));
    }

    public function close($request, int $id)
    {
        $this->service->close($id, $request->userId);
        return json(Response::success(null, 'Ticket closed'));
    }

    public function index($request)
    {
        $query = Ticket::query();

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($priority = $request->input('priority')) {
            $query->where('priority', $priority);
        }
        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }
        if ($assignedTo = $request->input('assigned_to')) {
            $query->where('assigned_to', $assignedTo);
        }
        if ($request->input('sla_breached')) {
            $query->where('sla_deadline', '<', date('Y-m-d H:i:s'))
                  ->whereIn('status', ['open', 'in_progress']);
        }

        $tickets = $query->with('user.profile')->orderBy('created_at')->paginate(30);
        return json(Response::paginated($tickets->items(), $tickets->total(), $request->input('page', 1), 30));
    }

    public function assign($request, int $id)
    {
        $this->service->assignTicket($id, $request->input('staff_id'));
        return json(Response::success(null, 'Ticket assigned'));
    }
}
