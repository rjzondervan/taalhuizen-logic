<?php


namespace App\Service;


use Conduction\CommonGroundBundle\Service\CommonGroundService;
use DateTime;
use Exception;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ParticipationService
{
    private CommonGroundService $commonGroundService;
    private ParameterBagInterface $parameterBag;

    public function __construct(CommonGroundService $commonGroundService, ParameterBagInterface $parameterBag)
    {
        $this->commonGroundService = $commonGroundService;
        $this->parameterBag = $parameterBag;
    }

    /**
     * Updates and returns a participation with the correct status, depending on the participation type (providerOption).
     *
     * @param array $participation
     * @return array
     */
    public function setStatus(array $participation): array
    {
        if ($participation['providerOption'] == 'OTHER' && $participation['status'] == "REFERRED") {
            $participationUpdate = [
                'status'            => "ACTIVE",
                'learningNeed'      => $participation['learningNeed']['id'],
                'providerOption'    => $participation['providerOption']
            ];
            return $this->commonGroundService->updateResource($participationUpdate,
                ['component' => 'gateway', 'type' => 'participations', 'id' => $participation['id']]
            );
        }

        return $participation;
    }

    /**
     * Checks if the student.referred datetime of the given participation.learningNeed.student needs to be set, and if so does this and returns the update student.
     *
     * @param array $participation
     * @return array
     */
    public function checkStudentReferred(array $participation): array
    {
        $totalStudentParticipations = $this->commonGroundService->getResourceList(
            ['component' => 'gateway', 'type' => 'participations'],
            ['learningNeed.student.id' => $participation['learningNeed']['student']['id'], 'fields' => null],
            false
        )['total'];

        if ($totalStudentParticipations == 1) {
            $studentUpdate = [
                'person'    => $this->commonGroundService->getUuidFromUrl($participation['learningNeed']['student']['person']['@id']), // we don't have 'id' here, maxDepth...
                'referred'  => $participation['@dateCreated']
            ];

            return $this->commonGroundService->updateResource($studentUpdate,
                ['component' => 'gateway', 'type' => 'students', 'id' => $participation['learningNeed']['student']['id']]
            );
        }

        return [];
    }

    /**
     * Function for cron-job that checks if participation status need to be set to COMPLETED. (see /api/src/Command/ParticipationSatusCommand.php & /api/helm/templates/cron-participation-status.yaml)
     *
     * @param SymfonyStyle $io
     * @param int $page
     * @param int $errorCount
     * @param bool $first
     * @return float|int
     */
    public function updateCompletedParticipations(SymfonyStyle $io, int $page = 1, int $errorCount = 0, bool $first = true)
    {
        $today = new DateTime();
        $today = $today->format('Y-m-d');
        $participations = $this->commonGroundService->getResourceList(
            ['component' => 'gateway', 'type' => 'participations'],
            [
                'page' => $page,
                'end[before]' => $today,
                'status' => 'ACTIVE',
                'fields' => ['status', 'learningNeed.id', 'providerOption', 'end']
            ],
            false
        );
        if ($first) {
            $io->text('Found '.$participations['total'].' participations');
            $io->progressStart($participations['total']);
        }

        foreach ($participations['results'] as $participation) {
            $io->text("");
            $io->section("Updating participation {$participation['id']}");
            try {
                $participationUpdate = [
                    'status'            => "COMPLETED",
                    'learningNeed'      => $participation['learningNeed']['id'],
                    'providerOption'    => $participation['providerOption']
                ];
                $this->commonGroundService->updateResource($participationUpdate,
                    ['component' => 'gateway', 'type' => 'participations', 'id' => $participation['id']]
                );
                $io->text('Participation with end: '.$participation['end'].' now has status COMPLETED');
            } catch (RequestException $exception) {
                $io->error($exception->getMessage());
                $errorCount++;
            }
            $io->progressAdvance();
        }

        if ($participations['page'] !== $participations['pages']) {
            return $this->updateCompletedParticipations($io, $page + 1, $errorCount, false);
        }

        $io->progressFinish();
        if ($participations['total'] == 0) {
            return 0;
        }
        return round($errorCount/$participations['total']*100) == 0 && $errorCount > 0 ? 1 : round($errorCount/$participations['total']*100);
    }

    /**
     * Function for ValuesCommand (see /api/src/Command/ValuesCommand.php)
     *
     * @param SymfonyStyle $io
     * @param int $page
     * @param int $errorCount
     * @param bool $first
     * @return float|int
     */
    public function updateGatewayDateTimeValues(SymfonyStyle $io, int $page = 1, int $errorCount = 0, bool $first = true)
    {
        $values = $this->commonGroundService->getResourceList(
            ['component' => 'gatewayAdmin', 'type' => 'values'],
            ['page' => $page, 'exists[dateTimeValue]' => 'true', 'exists[stringValue]' => 'false'],
            false
        );
        if ($first) {
            $io->text('Found '.$values['hydra:totalItems'].' values');
            $io->progressStart($values['hydra:totalItems']);
        }

        foreach ($values['hydra:member'] as $value) {
            $io->text("");
            $io->section("Updating value {$value['id']}");
            try {
                $date = new DateTime($value['dateTimeValue']);
                $date = $date->format('Y-m-d H:i:s');
                $updateValue = ['stringValue' => $date];
                $this->commonGroundService->updateResource($updateValue,
                    ['component' => 'gatewayAdmin', 'type' => 'values', 'id' => $value['id']]
                );
                $io->text('Value now has stringValue: '.$date);
            } catch (RequestException | Exception $exception) {
                $io->error($exception->getMessage());
                $errorCount++;
            }
            $io->progressAdvance();
        }

        if (isset($values['hydra:view']['hydra:next'])) {
            return $this->updateGatewayDateTimeValues($io, $page + 1, $errorCount, false);
        }

        $io->progressFinish();
        if ($values['hydra:totalItems'] == 0) {
            return 0;
        }
        return round($errorCount/$values['hydra:totalItems']*100) == 0 && $errorCount > 0 ? 1 : round($errorCount/$values['hydra:totalItems']*100);
    }
}
