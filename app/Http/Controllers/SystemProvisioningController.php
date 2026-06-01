<?php

namespace App\Http\Controllers;

use App\Services\CpanelDomainLookupService;
use App\Services\CpanelOrphanResourceService;
use App\Services\ProvisioningIntegrityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SystemProvisioningController extends Controller
{
    /**
     * Injeta os serviços de diagnóstico e saneamento do provisionamento cPanel.
     */
    public function __construct(
        private readonly ProvisioningIntegrityService $provisioningIntegrityService,
        private readonly CpanelDomainLookupService $cpanelDomainLookupService,
        private readonly CpanelOrphanResourceService $cpanelOrphanResourceService,
    ) {
    }

    /**
     * Exibe o diagnóstico dos requisitos para provisionar novos tenants.
     */
    public function integrity(): View
    {
        return view('pages.system.settings-provisioning-integrity', [
            'integrity' => $this->provisioningIntegrityService->check(),
        ]);
    }

    /**
     * Consulta se um domínio existe na conta cPanel configurada.
     */
    public function domainLookup(Request $request): View
    {
        $lookup = null;
        $domain = $request->input('domain');

        if ($domain !== null && $domain !== '') {
            $data = $request->validate([
                'domain' => ['required', 'string', 'max:255'],
            ]);

            $lookup = $this->cpanelDomainLookupService->find($data['domain']);
        }

        return view('pages.system.settings-cpanel-domain-lookup', [
            'domain' => $domain,
            'lookup' => $lookup,
        ]);
    }

    /**
     * Exibe recursos do cPanel que não possuem vínculo esperado na Central.
     */
    public function orphanResources(): View
    {
        return view('pages.system.settings-cpanel-orphan-resources', [
            'diagnostic' => $this->cpanelOrphanResourceService->scan(),
            'removalResults' => session('orphan_removal_results'),
        ]);
    }

    /**
     * Remove um recurso órfão após revalidar o vínculo local.
     */
    public function removeOrphanResource(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'confirmation' => ['required', 'string', 'max:255'],
        ]);

        if ($data['confirmation'] !== $data['name']) {
            return redirect()
                ->route('system.settings.provisioning.orphans')
                ->with('error', 'Confirmação inválida. Nenhum recurso foi removido.');
        }

        $result = $this->cpanelOrphanResourceService->removeOne($data['type'], $data['name']);

        return redirect()
            ->route('system.settings.provisioning.orphans')
            ->with('orphan_removal_results', [$result]);
    }

    /**
     * Remove em lote os recursos que continuam órfãos após nova varredura.
     */
    public function removeOrphanResourcesBatch(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->cpanelOrphanResourceService->removeBatch($data['confirmation']);

        if (!($result['success'] ?? false)) {
            return redirect()
                ->route('system.settings.provisioning.orphans')
                ->with('error', $result['message']);
        }

        return redirect()
            ->route('system.settings.provisioning.orphans')
            ->with('message', $result['message'])
            ->with('orphan_removal_results', $result['items']);
    }
}
