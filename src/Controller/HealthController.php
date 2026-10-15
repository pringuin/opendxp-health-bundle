<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Controller;

use OpenDxp\Controller\Traits\JsonHelperTrait;
use OpenDxp\Controller\UserAwareController;
use Pringuin\OpenDxpHealthBundle\Service\HealthCheckAlreadyRunningException;
use Pringuin\OpenDxpHealthBundle\Service\HealthCheckRunner;
use Pringuin\OpenDxpHealthBundle\Service\HealthReportMailer;
use Pringuin\OpenDxpHealthBundle\Service\HealthReportStorage;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * @internal
 */
class HealthController extends UserAwareController
{
    use JsonHelperTrait;

    public const string PERMISSION = 'pringuin_health';

    public function __construct(
        private readonly HealthCheckRunner $runner,
        private readonly HealthReportStorage $storage,
        private readonly HealthReportMailer $mailer,
    ) {
    }

    #[Route('/report', name: 'pringuin_opendxp_health_report', methods: ['GET'])]
    public function reportAction(): JsonResponse
    {
        $this->checkPermission(self::PERMISSION);

        $report = $this->storage->getLastReport();

        return $this->jsonResponse([
            'success' => true,
            'report' => $report?->toArray(),
        ]);
    }

    #[Route('/run', name: 'pringuin_opendxp_health_run', methods: ['POST'])]
    public function runAction(): JsonResponse
    {
        $this->checkPermission(self::PERMISSION);

        try {
            $report = $this->runner->run();
        } catch (HealthCheckAlreadyRunningException $exception) {
            return $this->jsonResponse(
                ['success' => false, 'message' => $exception->getMessage()],
                Response::HTTP_CONFLICT
            );
        }
        $this->storage->save($report);

        return $this->jsonResponse([
            'success' => true,
            'report' => $report->toArray(),
        ]);
    }

    #[Route('/send-mail', name: 'pringuin_opendxp_health_send_mail', methods: ['POST'])]
    public function sendMailAction(): JsonResponse
    {
        $this->checkPermission(self::PERMISSION);

        $report = $this->storage->getLastReport();
        if ($report === null) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'No health report available yet. Run the health check first.',
            ]);
        }

        try {
            $this->mailer->send($report);
        } catch (Throwable $exception) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        }

        return $this->jsonResponse([
            'success' => true,
            'recipient' => $this->mailer->resolveRecipient(),
        ]);
    }
}
