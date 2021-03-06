<?php

namespace Infernobass7\PrintNode;

use Illuminate\Support\Facades\Storage;
use Infernobass7\PrintNode\Exceptions\InvalidCredentialsException;
use Infernobass7\PrintNode\Exceptions\InvalidPrinterSettingUsedException;
use Infernobass7\PrintNode\Exceptions\PrinterNotDefinedException;
use Infernobass7\PrintNode\Exceptions\PrinterNotOnlineException;

class PrintJob extends Entity
{
    protected $uri = 'printjobs';
    protected $printer;
    protected $foreignObjects = [
        'printer' => Printer::class,
    ];
    protected $newPrintJob;

    /**
     * Create a Print Job
     *
     * @param array $attributes
     * @param null $printer
     * @throws PrinterNotOnlineException
     */
    public function __construct($attributes = [], $printer = null)
    {
        parent::__construct($attributes);

        $this->setQuantity(1);

        if ($printer) {
            $this->setPrinter($printer);
        }

        if (! array_key_exists('options', $attributes)) {
            $this->setAttribute('options', config('printnode.options'));
        }
    }

    /**
     * Print current Print Job
     *
     * @param null $printer
     * @return mixed
     * @throws PrinterNotDefinedException
     * @throws PrinterNotOnlineException
     * @throws InvalidPrinterSettingUsedException
     */
    public function print($printer = null)
    {
        if ($printer) {
            $this->setPrinter($printer);
        }

        if ($this->printer) {
            $this->checkSettings();

            return $this->client->post("{$this->uri}", ['json' => $this->toArray()]);
        }

        throw new PrinterNotDefinedException();
    }

    /**
     * @param $path
     * @param string $disk
     * @param bool $raw
     * @return $this
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function setFile($path, $disk = 'local', $raw = false)
    {
        $this->contentType = $raw ? 'raw_base64' : 'pdf_base64';
        $this->content = base64_encode(Storage::disk($disk)->get($path));

        return $this;
    }

    /**
     * @param $uri
     * @param null $credentials
     * @param bool $raw
     * @return $this
     * @throws InvalidCredentialsException
     */
    public function setUri($uri, $credentials = null, $raw = false)
    {
        $this->contentType = $raw ? 'raw_uri' : 'pdf_uri';
        $this->content = $uri;

        if ($credentials) {
            $this->setAuthentication($credentials);
        }

        return $this;
    }

    public function setSource(string $source)
    {
        $this->source = $source;

        return $this;
    }

    public function setCopies(int $quantity)
    {
        $this->setOptions(['copies' => $quantity > 0 ? $quantity : 1]);

        return $this;
    }

    public function setExpireAfter(int $expireAfter)
    {
        $this->expireAfter = $expireAfter;

        return $this;
    }

    /**
     * Sets the number of times this print job is sent to a printer.
     *
     * @param int $quantity
     *
     * @return $this
     */
    public function setQuantity(int $quantity)
    {
        $this->qty = $quantity;

        return $this;
    }

    public function setOptions($options = [])
    {
        foreach ($options as $key => $value) {
            $this->options[$key] = $value;
        }

        return $this;
    }

    /**
     * @param $credentials
     * @param bool $basic
     * @return $this
     * @throws InvalidCredentialsException
     */
    public function setAuthentication($credentials, $basic = true)
    {
        if (! array_key_exists('username', $credentials) || ! array_key_exists('password', $credentials)) {
            throw new InvalidCredentialsException('Credentials do not contain either the username or password.');
        }

        $this->authentication = [
            'type'        => $basic ? 'BasicAuth' : 'DigestAuth',
            'credentials' => [
                'user' => $credentials['username'],
                'pass' => $credentials['password'],
            ],
        ];

        return $this;
    }

    /**
     * @param $printer
     * @return $this
     * @throws PrinterNotOnlineException
     */
    public function setPrinter($printer)
    {
        if ($printer instanceof Printer) {
            $this->printer = $printer;
            $printer = $printer->id;
        } else {
            $this->printer = app(Printer::class)->get($printer);
        }

        if (!$this->printer->isOnline()) {
            throw new PrinterNotOnlineException();
        }

        $this->setAttribute('printerId', $printer);

        return $this;
    }

    /**
     * @throws InvalidPrinterSettingUsedException
     * @throws PrinterNotOnlineException
     */
    public function checkSettings()
    {
        if (array_key_exists('copies', $this->options)) {
            if (($max = $this->printer->capabilities['copies']) > $this->options['copies']) {
                $copies = $this->qty * $this->options['copies'];

                if (($rem = $copies % $max) > 0) {
                    $this->newPrintJob = (new self($this->attributes))->setQuantity(1)->setOptions(['copies' => $rem]);
                    $copies -= $rem;
                }

                $this->setQuantity(intval($copies / $max));
                $this->setOptions(['copies' => $max]);
            }
        }

        if (array_key_exists('paper', $this->options)) {
            if (! array_key_exists($this->options['paper'], $this->printer->capabilities['papers'])) {
                throw new InvalidPrinterSettingUsedException('This Paper selection is not supported by the printer.');
            }
        }

        if (array_key_exists('media', $this->options)) {
            if (! in_array($this->options['media'], $this->printer->capabilities['medias'])) {
                throw new InvalidPrinterSettingUsedException('This Media selection is not supported by the printer.');
            }
        }

        if (array_key_exists('dpi', $this->options)) {
            if (! in_array($this->options['dpi'], $this->printer->capabilities['dpis'])) {
                throw new InvalidPrinterSettingUsedException('This DPI selection is not supported by the printer.');
            }
        }

        if (array_key_exists('color', $this->options)) {
            if (! $this->printer->capabilities['color'] && $this->options['color']) {
                $this->setOptions(['color' => false]);
            }
        }
    }

    public function getPrinterName()
    {
        if (!$this->printer) {
            return null;
        }

        return $this->printer->name;
    }
}
