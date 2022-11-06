<?php

namespace App\Commands;

use App\Enums\Status;
use App\Services\Avs;
use App\Supports\CardValidate;
use LaravelZero\Framework\Commands\Command;
use Spatie\Fork\Fork;

use function Termwind\render;
use function Termwind\terminal;

class CheckCommand extends Command
{
    use CardValidate;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'check {--speed : Request ratio}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $lists = [
            '4060320365641142|07|27|102',
            '4342582025783636|05|25|362',
            '5112710300142583|10|24|033',
            '5143773636747908|05|26|806',
            '4430470022842659|06|24|326',
            '4879170019292706|10|26|923',
            '4342562440772901|02|26|627',
            '4403934467322223|05|28|498',
            '4403932464325124|02|26|671'
        ];

        $speed = $this->option('speed');
        if (!$speed) {
            $speed = $this->ask('input speed', 10);
        }

        $chunks = array_chunk($lists, $speed);
        foreach ($chunks as $chunk) {
            $jobs = [];

            foreach ($chunk as $card) {
                if (!$this->validateRawCard($card)) {
                    continue;
                }

                $card = $this->parseCard($card);
                $jobs[] = function () use ($card) {
                    $avs        = new Avs($card);
                    $result     = $avs();

                    /**
                     * @var Status
                     */
                    $status = $result['result'];
                    $format = implode(' ', $card->toArray());

                    if (!is_dir('results')) {
                        @mkdir('results');
                    }

                    file_put_contents(
                        'results/' . $status->value . '.txt',
                        $format . PHP_EOL,
                        FILE_APPEND
                    );

                    if ($status != Status::LIVE) {
                        return render(<<<HTML
                            <div class="text-red-500">{$format}</div>
                        HTML);
                    }

                    return $this->info($format);
                };
            }

            //
            terminal()->clear();
            $this->info('Starting ' . $speed . ' request.');
            Fork::new()->run(...$jobs);

            // 3 detik
            sleep(3);
            terminal()->clear();
            $this->info('Sleep for 3 second...');
        }
    }
}
