<?php
require_once ROOT_PATH . '/core/Controller.php';
require_once dirname(__DIR__) . '/models/Registry.php';

final class EdsWarrantyCardRegistryController extends Controller {
    public function index(): void {
        $this->requireTechnician();
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            header('Allow: GET');
            return;
        }

        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        $search = EdsWarrantyCardRegistry::normalizeSearch($_GET['q'] ?? '');
        $model = new EdsWarrantyCardRegistry($this->db->connect());
        $totalCount = $model->count($search);
        $totalPages = max(1, (int) ceil($totalCount / EdsWarrantyCardRegistry::PAGE_SIZE));
        $currentPage = $this->resolvePage($_GET['page'] ?? '1', $totalPages);
        $offset = ($currentPage - 1) * EdsWarrantyCardRegistry::PAGE_SIZE;

        $this->viewPath(dirname(__DIR__) . '/views/registry.php', [
            'cards' => $model->page($search, EdsWarrantyCardRegistry::PAGE_SIZE, $offset),
            'search' => $search,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
        ], 'eds-warranty-cards/registry');
    }

    private function resolvePage(mixed $value, int $totalPages): int {
        if (!is_string($value) || preg_match('/\A[0-9]+\z/', $value) !== 1 || ltrim($value, '0') === '') {
            return 1;
        }
        $normalized = ltrim($value, '0');
        if (EdsWarrantyCardNumber::compare($normalized, (string) $totalPages) > 0) {
            return $totalPages;
        }
        return (int) $normalized;
    }
}
