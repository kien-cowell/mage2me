<?php

namespace App;

use App\Builder\MagentoBuilder;
use App\CommandTraits\GithubAccessTraits;
use App\CommandTraits\MagentoAccessTraits;
use App\CommandTraits\MagentoVersionCommandTraits;
use App\Config\Composer;
use App\Config\Github;
use App\Presets\ValidatorPreset;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Validation\Validator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * @method $this addOptionMageVersion()
 * @method $this addOptionMageEdition()
 * @method $this addOptionNotifyMagento()
 * @method $this addOptionMagentoPublicKey()
 * @method $this addOptionMagentoPrivateKey()
 * @method $this addOptionGithubRateToken()
 * @method $this addOptionNotifyGithub()
 */
class DownloadCommand extends Command
{
    use MagentoVersionCommandTraits, MagentoAccessTraits, GithubAccessTraits;

    protected static $defaultName = "download";

    /**
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Downloads Magento 2 Commerce or Open Source')
            ->setHelp('This command downloads Magento 2 Commerce or Open Source at the version you request.')
            ->addArgument('save-to', InputArgument::OPTIONAL, "Save to directory", '.')
            ->addOption('dev', 'd', InputOption::VALUE_OPTIONAL,
                'Enable install of development branch or tag', false)
            ->addOptionMageEdition()
            ->addOptionMageVersion()
            ->addOptionMagentoPublicKey()
            ->addOptionMagentoPrivateKey()
            ->addOptionGithubRateToken()
            ->addOptionNotifyMagento()
            ->addOptionNotifyGithub();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateArguments($input, $output);
        $this->validateOptions($input, $output);

        try {
            $this->deployMagento($output, $input);
        } catch (\Exception $exception) {
            $output->write('<error>' . $exception->getMessage() . '</error>');
        }

        $output->writeln('<info></info>');
        $output->writeln('<info>    Magento ' . $input->getOption($this->keyMageEdition()) . ' ' . $input->getOption($this->keyMageVersion()) . ' Downloaded.</info>');
        $output->writeln('<info>    Access the Magento directory and run: composer install php bin/magento setup:install #Optional --use-sample-data`</info>');
        $output->writeln('<info></info>');
        $output->writeln('<info>    1. composer install</info>');
        $output->writeln('<info>    2. php bin/magento setup:install #Optional --use-sample-data</info>');
        $output->writeln('<info></info>');
        $output->writeln('<info>      That\'s it!</info>');

        return 0;
    }

    /**
     * @param $errorCode
     */
    protected function killApp($errorCode = 1)
    {
        exit($errorCode);
    }

    /**
     * @param Validator $validator
     * @param OutputInterface $output
     */
    protected function handleErrors(Validator $validator, OutputInterface $output)
    {
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $output->writeln('<error>' . $message . '</error>');
            }
            $this->killApp();
        }
    }


    /**
     * Validate console arguments
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function validateArguments(InputInterface $input, OutputInterface $output)
    {
        $argumentValidation = ValidatorPreset::make(
            $input->getArguments(),
            [
                'save-to' => ['required', 'directoryExists' => function ($attributeName, $value, $fail) {
                    $filesystem = new Filesystem();
                    @$filesystem->ensureDirectoryExists($value);
                    if (!$filesystem->isDirectory($value)) {
                        $fail($attributeName . ' path ' . $value . ' does not exist and cannot be created.');
                    }
                },
                ]
            ],
            ['save-to.required' => 'save-to must be provided']
        );

        $this->handleErrors($argumentValidation, $output);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return $this
     */
    protected function validateOptions(InputInterface $input, OutputInterface $output)
    {
        $this->validateTokenFormat($input, $output);

        $output->writeln('<info>Validating token access</info>');
        $this->validateTokenAccess($input, $output);

        $output->writeln('<info>Validating Magento edition/version selection</info>');
        $this->validateMagentoVersion($input, $output);

        return $this;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    private function validateMagentoVersion(InputInterface $input, OutputInterface $output)
    {
        $versionValidation = ValidatorPreset::make(
            $input->getOptions(),
            [
                $this->keyMageEdition() => 'required|in:Open Source,Commerce',
                $this->keyMageVersion() => ['required', $this->ruleValidateMagentoVersion($input)]
            ],
            [
                'required' => ':attribute must be provided',
                $this->keyMageEdition() . '.in' => ':attribute must be "Open Source" or "Commerce"'
            ]
        );

        $this->handleErrors($versionValidation, $output);
    }

    /**
     * Validate provided tokens have appropriate format and are required
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    private function validateTokenFormat(InputInterface $input, OutputInterface $output)
    {
        $keyMagentoPublicToken = $this->keyMagentoAccessPublic();
        $keyMagentoPrivateToken = $this->keyMagentoAccessPrivate();
        $keyGithubToken = $this->keyGithubToken();
        $tokenInput = [
            $keyMagentoPublicToken => $input->getOption($keyMagentoPublicToken),
            $keyMagentoPrivateToken => $input->getOption($keyMagentoPrivateToken),
            $keyGithubToken => $input->getOption($keyGithubToken),
        ];
        $rules = [
            $keyGithubToken => 'required|min:15|max:255',
            $keyMagentoPublicToken => 'required|min:15|max:255',
            $keyMagentoPrivateToken => 'required|min:15|max:255',
        ];

        $this->handleErrors(
            ValidatorPreset::make(
                $tokenInput,
                $rules,
                [
                    'required' => ':attribute is required',
                    'min' => ':attribute must be at least :min characters',
                    'max' => ':attribute can not be more than :max characters'
                ]
            ),
            $output
        );
    }

    /**
     * Ensure provided credentials have authorized access
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    private function validateTokenAccess(InputInterface $input, OutputInterface $output)
    {
        $keyMagentoPublicToken = $this->keyMagentoAccessPublic();
        $keyMagentoPrivateToken = $this->keyMagentoAccessPrivate();
        $keyGithubToken = $this->keyGithubToken();
        $validateCredentialsAuthorized = ValidatorPreset::make(
            [
                'magento-credentials' => [
                    'public' => $input->getOption($keyMagentoPublicToken),
                    'private' => $input->getOption($keyMagentoPrivateToken)
                ],
                $keyGithubToken => $input->getOption($keyGithubToken)
            ],
            [
                'magento-credentials' => $this->ruleMagentoAuthorizedAccess(),
                $keyGithubToken => $this->ruleGithubAuthorizedAccess()
            ]
        );
        $this->handleErrors($validateCredentialsAuthorized, $output);
    }

    /**
     * Validator rule testing magento credentials have authorized access
     *
     * @return \Closure
     */
    protected function ruleMagentoAuthorizedAccess()
    {
        return function ($attributeName, $credentials, $fail) {
            $repoHost = Composer::load()['magento']['repo']['host'];
            $magentoRepoClient = $this->mageRepoClient($credentials['public'], $credentials['private']);
            $response = $magentoRepoClient->request('GET', Composer::load()['magento']['repo']['packages']);
            if ($response->getStatusCode() !== 200) {
                $fail('Magento credentials are invalid. Could not authenticate against ' . $repoHost);
            }
        };
    }

    /**
     * @return \Closure
     */
    private function ruleGithubAuthorizedAccess()
    {
        return function ($attributeName, $githubToken, $fail) {
            $auth_url = Github::load()['user']['auth_url'];
            $githubUserAuth = HttpClient::createForBaseUri(
                $auth_url,
                [
                    'headers' => [
                        'Authorization' => 'token ' . $githubToken
                    ]
                ]
            );

            $response = $githubUserAuth->request('GET', '/');
            if ($response->getStatusCode() !== 200) {
                $fail('Could not validate your GitHub token against ' . $auth_url);
            }
        };
    }

    /**
     * @param  $username
     * @param  $password
     * @return \Symfony\Contracts\HttpClient\HttpClientInterface
     */
    protected function mageRepoClient($username, $password)
    {
        $repoHost = Composer::load()['magento']['repo']['host'];
        return HttpClient::createForBaseUri(
            'https://' . $repoHost,
            [
                'auth_basic' => [strval($username), strval($password)],
            ]
        );
    }

    /**
     * @param $githubToken
     * @return \Symfony\Contracts\HttpClient\HttpClientInterface
     */
    protected function mageGithubClient($githubToken)
    {
        $repoHost = Github::load()['magento']['Open Source'];
        return HttpClient::createForBaseUri(
            $repoHost,
            [
                'headers' => [
                    'Authorization' => 'token ' . $githubToken
                ]
            ]
        );
    }

    /**
     * @param InputInterface $input
     * @return \Closure
     */
    protected function ruleValidateMagentoVersion(InputInterface $input)
    {
        return function ($attributeName, $mageVersion, $fail) use ($input) {
            $user = $input->getOption($this->keyMagentoAccessPublic());
            $password = $input->getOption($this->keyMagentoAccessPrivate());
            $userPreferredMageEdition = $input->getOption($this->keyMageEdition());
            $packagesUrl = Composer::load()['magento']['repo']['packages'];
            if ($this->isDevInstallRequest($input)) {
                if (strtolower($userPreferredMageEdition) !== 'open source') {
                    $fail('Sorry, `--dev` is only compatible with Magento Open Source');
                }
                $pingGithubBranch = $this->mageGithubClient($input->getOption($this->keyGithubToken()))
                    ->request('GET', "tree/{$mageVersion}");

                if ($pingGithubBranch->getStatusCode() !== 200) {
                    $fail('Magento branch or tag "' . $mageVersion . '" does not exist on Github at '
                        . $pingGithubBranch->getInfo('url'));
                }
                return;
            }

            $response = $this->mageRepoClient($user, $password)
                ->request('GET', $packagesUrl);
            $magentoRepoData = $response
                ->toArray(false);

            $magentoPackages = [];
            if (array_key_exists('packages', $magentoRepoData)) {
                $magentoPackages = $magentoRepoData['packages'];
            }

            if (empty($magentoPackages)) {
                $fail('Magento failed to provide package data when requesting ' . $response->getInfo()['url'] . '');
            }

            $mageEditionPackage = Composer::load()['magento'][$userPreferredMageEdition]['package'];
            $magentoHasEdition = array_key_exists($mageEditionPackage, $magentoPackages);
            if (!$magentoHasEdition) {
                $fail('Failed to find Magento edition ' . $mageEditionPackage . ' in Magento Composer repository');
            }

            $magentoEditionVersions = $magentoPackages[$mageEditionPackage];
            $editionHasVersion = array_key_exists($mageVersion, $magentoEditionVersions);

            if (!$editionHasVersion) {
                $fail('Cannot install from Composer: '
                    . 'Magento version ' . $mageVersion . ' is not available in the Magento repo for '
                    . $userPreferredMageEdition . ' (' . $mageEditionPackage . '). '
                    . "\n\n"
                    . 'Use `--dev` to try installing from Github.');
            }
        };
    }

    /**
     * @param OutputInterface $output
     * @param InputInterface $input
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    protected function deployMagento(OutputInterface $output, InputInterface $input)
    {
        $output->writeln('<info>Creating Project.</info>');
        $outDir = $input->getArgument('save-to');
        $output->writeln(
            '<comment>Working from temp directory. Installing to directory: '. $outDir .'</comment>'
        );

        if ($this->isDevInstallRequest($input)) {
            MagentoBuilder::deployFromGithub(
                function ($type, $buffer) use ($output) {
                    $output->writeln($buffer);
                },
                $input->getOption($this->keyMageVersion()),
                $outDir,
                Github::load()['magento']['Open Source'],
                $input->getOption($this->keyMagentoAccessPublic()),
                $input->getOption($this->keyMagentoAccessPrivate()),
                $input->getOption($this->keyGithubToken())
            );
            return;
        }
        MagentoBuilder::deploy(
            function ($type, $buffer) use ($output) {
                $output->writeln($buffer);
            },
            Composer::load()['magento'][$input->getOption($this->keyMageEdition())]['package'],
            $input->getOption($this->keyMageVersion()),
            $outDir,
            $input->getOption($this->keyMagentoAccessPublic()),
            $input->getOption($this->keyMagentoAccessPrivate()),
            $input->getOption($this->keyGithubToken())
        );
    }

    /**
     * @param InputInterface $input
     * @return bool
     */
    protected function isDevInstallRequest(InputInterface $input)
    {
        return $input->getOption('dev') !== false;
    }
}
