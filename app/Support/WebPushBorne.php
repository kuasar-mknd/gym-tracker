<?php

declare(strict_types=1);

namespace App\Support;

use Generator;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Un WebPush dont l'envoi s'arrête quand son budget de temps est dépassé.
 *
 * Le canal joint les appareils d'un compte l'un après l'autre, et un pair muet
 * retient chaque envoi jusqu'au délai du client : dix appareils muets
 * approchent les 60 s au bout desquelles Horizon tue la tâche, qui occupe un
 * processus de la file `default` pendant ce temps. Passé le budget, les
 * appareils restants ne reçoivent pas cette notification : un push est une
 * remise au mieux, et le centre de notifications garde la sienne.
 */
class WebPushBorne extends WebPush
{
    /**
     * @param  array<string, array<string, string>>  $auth
     * @param  array<string, int|string>  $defaultOptions
     */
    public function __construct(
        private readonly float $budgetEnSecondes,
        array $auth = [],
        array $defaultOptions = [],
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        parent::__construct($auth, $defaultOptions, $client, $requestFactory, $streamFactory);
    }

    public function flush(?int $batchSize = null): Generator
    {
        $debut = hrtime(true);
        $restants = count($this->notifications ?? []);

        foreach (parent::flush($batchSize) as $rapport) {
            yield $rapport;

            $restants -= 1;

            if ($restants > 0 && (hrtime(true) - $debut) / 1_000_000_000 >= $this->budgetEnSecondes) {
                $this->vapidHeaders = [];

                Log::warning('Envoi push interrompu : budget de temps dépassé.', [
                    'budget' => $this->budgetEnSecondes,
                    'non_envoyes' => $restants,
                ]);

                return;
            }
        }
    }
}
