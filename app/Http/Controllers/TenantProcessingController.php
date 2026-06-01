<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

class TenantProcessingController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function process(Request $request)
    {
        // Extrai dados
        $data = $request->all();

        // Inicia consulta
        $query = $this->loadTables();

        // Filtra dados relevantes
        $query = $this->filters($query, $data);

        // Filtra pela busca
        $query = $this->search($query, $data);

        // Ordena resultados
        $query = $this->ordering($query, $data);

        // Retorna dados
        return $this->formatResults($query);
    }

    /**
     * Inicializa a consulta com junções e seleção de colunas.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function loadTables()
    {
        // Tabela principal
        return DB::table('tenants')
            ->leftJoin('tenants_runtime_statuses as runtime', 'runtime.tenant_id', '=', 'tenants.id');
    }

    /**
     * Aplica os filtros estruturados informados na listagem.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function filters($query, $data)
    {
        // Filtra por tipo de instalação
        if (!empty($data['payment_type']) && $data['payment_type'] !== 'all') {
            $query->where('tenants.type_installation', $data['payment_type']);
        }

        // Filtra por status (ativo/inativo)
        if (isset($data['status_filter']) && $data['status_filter'] !== 'all') {
            $query->where('tenants.status', (int) $data['status_filter']);
        }

        // Mantém compatibilidade com filtro antigo por array de status
        if (isset($data['status']) && is_array($data['status']) && !empty($data['status'])) {
            $query->whereIn('tenants.status', $data['status']);
        }

        return $query;
    }

    /**
     * Aplica filtros de pesquisa à consulta.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Database\Query\Builder
     */
    public function search($query, $data)
    {
        // Obtém dados da consulta
        $searchBy = $data['searchBy'] ?? null;

        // Realiza filtro na busca
        if ($searchBy != '') {
            // Realiza busca no nome
            $query->where('tenants.name', 'like', "%$searchBy%");
            // Permite busca direta por ID no mesmo campo
            $query->orWhere('tenants.id', $searchBy);
        }

        // Retorna consulta filtrada
        return $query;
    }

    /**
     * Aplica a ordenação à consulta.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Database\Query\Builder
     */
    public function ordering($query, $data)
    {
        // Ordena de acordo com a coluna desejada
        if (!empty($data['order'])) {

            // Direção e coluna
            $direction = $data['order'][0]['dir'];
            $orderThis = $data['order_by'];

            // Define qual a lógica de ordenação
            switch ($orderThis) {
                case 'name':
                    $column = 'tenants.name';
                    break;

                case 'created_at':
                    $column = 'tenants.created_at';
                    break;

                case 'status':
                    $column = 'tenants.status';
                    break;

                default:
                    $column = 'tenants.id';
                    break;
            }

            // Ordena a coluna
            return $query->orderBy($column, $direction);
        }

        // Mantém ordenação padrão por id quando não há ordenação explícita
        return $query;
    }

    /**
     * Formata os resultados para o DataTables.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Database\Query\Builder
     */
    public function formatResults($query)
    {
        // SELECT final
        $query->select(
            'tenants.id                as id',
            'tenants.name              as name',
            'tenants.type_installation as type_installation',
            'tenants.created_at        as created_at',
            DB::raw('COALESCE(runtime.db_last_version, 1) as db_last_version'),
            'runtime.db_error          as db_error',
            DB::raw('COALESCE(runtime.git_last_version, 0) as git_last_version'),
            'runtime.git_error         as git_error',
            DB::raw('COALESCE(runtime.sp_last_version, 0) as sp_last_version'),
            'runtime.sp_error          as sp_error',
            DB::raw('COALESCE(runtime.js_last_version, 0) as js_last_version'),
            'runtime.js_error          as js_error',
            'tenants.status            as status',
            'tenants.token             as token'
        );

        $query->selectSub(
            DB::table('tenants_domains')
                ->select('domain')
                ->whereColumn('tenants_domains.tenant_id', 'tenants.id')
                ->orderBy('tenants_domains.id')
                ->limit(1),
            'first_domain'
        );

        $query->selectSub(
            DB::table('tenants_domains')
                ->selectRaw('COUNT(*)')
                ->whereColumn('tenants_domains.tenant_id', 'tenants.id'),
            'domains_count'
        );

        $query->selectSub(
            DB::table('subscriptions_cycles as cycles')
                ->join('subscriptions', 'subscriptions.id', '=', 'cycles.subscription_id')
                ->select('cycles.end_date')
                ->whereColumn('subscriptions.tenant_id', 'tenants.id')
                ->orderByDesc('cycles.end_date')
                ->limit(1),
            'subscription_end_date'
        );

        return DataTables::query($query)
            ->editColumn('id', function ($row) {
                return '<span class="text-gray-700 fw-bold">#' . str_pad($row->id, 4, '0', STR_PAD_LEFT) . '</span>';
            })
            ->addColumn('name', function ($row) {
                $html = '<a href="' . route('tenants.show', $row->id) . '" class="text-gray-700 text-hover-primary fw-bold">'
                    . e($row->name)
                    . '</a><br>';

                if ((int) $row->domains_count > 0 && !empty($row->first_domain)) {
                    $html .= '<a href="https://' . e($row->first_domain) . '" target="_blank" class="text-gray-600 text-hover-primary m-0 text-center">'
                        . e($row->first_domain)
                        . '</a>';

                    if ((int) $row->domains_count > 1) {
                        $html .= ' <i class="fa-solid fa-circle-plus text-gray-500 fs-9"></i>';
                    }
                } else {
                    $html .= '<span class="badge badge-light-danger">Sem domínio</span>';
                }

                return $html;
            })

            ->addColumn('type', function ($row) {
                return match ($row->type_installation) {
                    'shared' => '<span class="badge badge-success">Compartilhada</span>',
                    'dedicated' => '<span class="badge badge-info">Dedicada</span>',
                    default => '<span class="badge badge-secondary">Desconhecido</span>',
                };
            })

            ->addColumn('expires_at', function ($row) {
                if (empty($row->subscription_end_date)) {
                    return '<span class="text-gray-600">Sem ciclo</span>';
                }

                return '<span class="text-gray-600">' . date('d/m/Y', strtotime($row->subscription_end_date)) . '</span>';
            })

            ->addColumn('bank', function ($row) {
                if ((int) $row->db_last_version === 0) {
                    return $this->statusErrorIcon($row->db_error ?? 'Banco de dados desatualizado');
                }

                return '<i class="fa-solid fa-circle-check text-success" data-bs-toggle="tooltip" data-bs-placement="top" title="Banco de dados atualizado"></i>';
            })

            ->addColumn('git', function ($row) {
                if ((int) $row->git_last_version === 0) {
                    return $this->statusErrorIcon($row->git_error ?? 'Git desatualizado');
                }

                return '<i class="fa-solid fa-circle-check text-success" data-bs-toggle="tooltip" data-bs-placement="top" title="Git atualizado"></i>';
            })

            ->addColumn('sp', function ($row) {
                if ((int) $row->sp_last_version === 0) {
                    return $this->statusErrorIcon($row->sp_error ?? 'Supervisor desatualizado');
                }

                return '<i class="fa-solid fa-circle-check text-success" data-bs-toggle="tooltip" data-bs-placement="top" title="Supervisor atualizado"></i>';
            })

            ->addColumn('js', function ($row) {
                if ($row->js_last_version != 1) {
                    return $this->statusErrorIcon($row->js_error ?? 'Javascript desatualizado');
                }

                return '<i class="fa-solid fa-circle-check text-success" data-bs-toggle="tooltip" data-bs-placement="top" title="Javascript atualizado"></i>';
            })

            ->addColumn('status', function ($row) {
                if ((int) $row->status === 0) {
                    return '<span class="badge badge-light-danger">Desabilitado</span>';
                }

                return '<span class="badge badge-light-success">Habilitado</span>';
            })

            ->addColumn('actions', function ($row) {
                $toggleText = (int) $row->status === 0 ? 'Ativar' : 'Desativar';
                $installationType = e($row->type_installation);

                $html = '<div class="d-flex gap-4 align-items-center">';
                $html .= '<a href="' . route('tenants.show', $row->id) . '" class="btn btn-icon btn-sm btn-primary btn-active-primary" data-bs-toggle="tooltip" data-bs-placement="top" title="Visualizar instalação">';
                $html .= '<i class="fa-solid fa-eye"></i>';
                $html .= '</a>';
                if ((int) $row->domains_count > 0 && !empty($row->first_domain) && !empty($row->token)) {
                    $html .= '<a href="https://' . e($row->first_domain) . '/acessar/' . e($row->token) . '" target="_blank" class="btn btn-icon btn-sm btn-light-primary btn-active-primary" data-bs-toggle="tooltip" data-bs-placement="top" title="Acessar tenant">';
                    $html .= '<i class="fa-solid fa-globe"></i>';
                    $html .= '</a>';
                }
                $html .= '<a href="#" class="btn btn-light-primary btn-active-light-primary btn-sm" data-kt-menu-trigger="hover" data-kt-menu-placement="bottom-end" data-kt-menu-flip="top-end">';
                $html .= '<i class="fa-solid fa-ellipsis-vertical p-0"></i>';
                $html .= '</a>';
                $html .= '<div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-bold fs-7 w-200px py-4" data-kt-menu="true">';
                $html .= '<div class="menu-item px-3"><a href="' . route('systems.run.scheduled.now.tenant', $row->id) . '" class="menu-link px-3 js-tenant-run-task-modal" data-tenant-name="' . e($row->name) . '" data-tenant-id="' . e($row->id) . '"><i class="fa-solid fa-list-check me-2"></i>Executar Tarefas</a></div>';
                if (app()->environment(['local', 'testing'])) {
                    $html .= '<div class="menu-item px-3"><a href="' . route('systems.reset.plan', $row->id) . '" class="menu-link px-3 js-tenant-action-confirm" data-action-type="reset-plan" data-tenant-installation-type="' . $installationType . '" data-tenant-id="' . e($row->id) . '" data-action-label="resetar plano, assinaturas e pedidos" data-tenant-name="' . e($row->name) . '"><i class="fa-solid fa-box-open me-2"></i>Resetar Plano e Histórico</a></div>';
                }
                $html .= '<div class="menu-item px-3"><a href="' . route('systems.update.database', $row->id) . '" class="menu-link px-3 js-tenant-action-confirm" data-action-type="db" data-tenant-installation-type="' . $installationType . '" data-tenant-id="' . e($row->id) . '" data-action-label="atualizar banco de dados" data-tenant-name="' . e($row->name) . '"><i class="fa-solid fa-database me-2"></i>Atualiza banco de dados</a></div>';
                $html .= '<div class="menu-item px-3"><a href="' . route('systems.update.git', $row->id) . '" class="menu-link px-3 js-tenant-action-confirm" data-action-type="git" data-tenant-installation-type="' . $installationType . '" data-tenant-id="' . e($row->id) . '" data-action-label="atualizar git" data-tenant-name="' . e($row->name) . '"><i class="fa-solid fa-code me-2"></i>Atualiza git</a></div>';
                $html .= '<div class="menu-item px-3"><a href="' . route('systems.update.supervisor', $row->id) . '" class="menu-link px-3 js-tenant-action-confirm" data-action-type="sp" data-tenant-installation-type="' . $installationType . '" data-tenant-id="' . e($row->id) . '" data-action-label="reiniciar filas" data-tenant-name="' . e($row->name) . '"><i class="fa-solid fa-arrows-rotate me-2"></i>Reiniciar Filas</a></div>';
                $html .= '<div class="menu-item px-3"><a href="' . route('systems.update.javascript', $row->id) . '" class="menu-link px-3 js-tenant-action-confirm" data-action-type="js" data-tenant-installation-type="' . $installationType . '" data-tenant-id="' . e($row->id) . '" data-action-label="buildar Javascript" data-tenant-name="' . e($row->name) . '"><i class="fa-brands fa-js me-2"></i>Buildar JS</a></div>';
                $html .= '<div class="menu-item px-3"><a href="' . route('tenants.destroy', $row->id) . '" class="menu-link px-3 js-tenant-toggle-status" data-action-label="' . e(mb_strtolower($toggleText)) . '" data-tenant-id="' . e($row->id) . '" data-tenant-name="' . e($row->name) . '"><i class="fa-solid fa-toggle-off me-2"></i>' . $toggleText . '</a></div>';

                $html .= '</div></div>';

                return $html;
            })

            ->rawColumns(['id', 'name', 'type', 'expires_at', 'bank', 'git', 'sp', 'js', 'status', 'actions'])
            ->make(true);
    }

    /**
     * Monta o ícone de erro com conteúdo clicável para abrir no modal.
     */
    private function statusErrorIcon($message): string
    {
        $safeMessage = e($message);

        return '<i class="fa-solid fa-circle-xmark text-danger cursor-pointer js-tenant-error-detail" data-bs-toggle="tooltip" data-bs-placement="top" title="' . $safeMessage . '" data-error-content="' . $safeMessage . '"></i>';
    }
}
