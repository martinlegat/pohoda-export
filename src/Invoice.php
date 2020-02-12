<?php

namespace Pohoda;

use Pohoda\Export\Address;
use SimpleXMLElement;
use DateTime;


class Invoice
{
    const NS = 'http://www.stormware.cz/schema/version_2/invoice.xsd';

    const INVOICE_TYPE = self::TYPE_ISSUED_INVOICE; //BC

    /** Faktura */
    const TYPE_ISSUED_INVOICE = "issuedInvoice";
    /** Dobropis */
    const TYPE_ISSUED_CREDIT_NOTICE = "issuedCreditNotice";
    /** Vrubopis */
    const TYPE_ISSUED_DEBIT_NOTE = "issuedDebitNote";
    /** Zálohová faktura */
    const TYPE_ISSUED_ADVANCE_INVOICE = "issuedAdvanceInvoice";
    /** Ostatní pohledávka */
    const TYPE_RECEIVABLE = "receivable";
    /** Proforma faktura */
    const TYPE_ISSUED_PROFORMA_INVOICE = "issuedProformaInvoice";
    /** Penále */
    const TYPE_PENALTY = "penalty";
    /** Vydaný opravný daňový doklad (jen CZ verze) */
    const TYPE_ISSUED_CORRECTIVE_TAX = "issuedCorrectiveTax";
    /** Přijatá faktura */
    const TYPE_RECEIVED_INVOICE = "receivedInvoice";
    /** Přijatý dobropis */
    const TYPE_RECEIVED_CREDIT_NOTICE = "receivedCreditNotice";
    /** Přijatý vrubopis */
    const TYPE_RECEIVED_DEBIT_NOTE = "receivedDebitNote";
    /** Přijatá zálohová faktura */
    const TYPE_RECEIVED_ADVANCE_INVOICE = "receivedAdvanceInvoice";
    /** Závazek */
    const TYPE_COMMITMENT = "commitment";
    /** Přijatá proforma faktura */
    const TYPE_RECEIVED_PROFORMA_INVOICE = "receivedProformaInvoice";
    /** Přijatý opravný daňový doklad (jen CZ verze) */
    const TYPE_RECEIVED_CORRECTIVE_TAX = "receivedCorrectiveTax";

    /** Cizí měna. */
    const PAYMENT_TYPE_FOREIGN_CURRENCY = 'foreignCurrency';
    /** Hotovost. */
    const PAYMENT_TYPE_CASH = 'cash';
    /** Ostatní. */
    const PAYMENT_TYPE_OTHER = 'other';
    /** Platební karta. */
    const PAYMENT_TYPE_CREDIT_CARD = 'creditCard';
    /** Převodem. */
    const PAYMENT_TYPE_TRANSFER = 'transfer';
    /** Stravenka. */
    const PAYMENT_TYPE_TICKET = 'ticket';
    /** Šekem. */
    const PAYMENT_TYPE_CHEQUE = 'cheque';
    /** Záloha. */
    const PAYMENT_TYPE_ADVANCE = 'advance';
    /** Dobírka. */
    const PAYMENT_TYPE_DELIVERY = 'delivery';

    private $cancel; //cislo stornovaneho dokumentu
    private $cancelNumber; //ciselna rada pro storno

    private $withVAT = false;

    private $type = self::TYPE_ISSUED_INVOICE;
    private $paymentType = self::PAYMENT_TYPE_OTHER;
    private $roundingDocument = 'math2one';
    private $roundingVAT = 'none';

    private $varNum;
    private $date;
    private $dateTax;
    private $dateAccounting;
    private $dateDue;
    private $dateOrder; //datum objednani
    private $text;
    private $bankShortcut = '';
    private $note;

    private $paymentTypeString; //forma uhrady
    private $accounting;
    private $symbolicNumber = '0308';

    private $priceNone;
    private $priceLow;
    private $priceLowVAT;
    private $priceLowSum;
    private $priceHigh;
    private $priceHightVAT;
    private $priceHighSum;

    private $numberOrder; //vlastni cislo objednavky

    private $centre; //stredisko
    private $activity; //cinnost
    /** Zakazka
     * @var string
     */
    private $contract;

    private $items = [];

    private $myIdentity = [];

    /** @var Address */
    private $customerAddress;

    private $id;

    /** @var string - kod meny */
    private $foreignCurrency;

    /** @var float kurz meny - např. 25.5 pro euro */
    private $rate;

    /** @var int mnozstvi cizi meny pro kurzovy prepocet */
    private $amount = 1;

    /** @var float */
    private $foreignCurrencyPriceSum;

    private $required = ['date', 'varNum', 'text'];

    public function __construct($id)
    {
        $this->id = $id;
    }

    /**
     * @param $bool
     * @throws InvoiceException
     */
    public function setWithVat($bool)
    {
        if(is_bool($bool) === false)
            throw new InvoiceException($this->getId().": setWithVat must use boolean value");
        $this->withVAT = $bool;
    }

    public function getId()
    {
        return $this->id;
    }

    public function isValid()
    {
        return $this->checkRequired();
    }

    /**
     * @return bool
     * @throws InvoiceException
     */
    private function checkRequired()
    {
        foreach($this->required as $param)
        {
            if(!isset($this->$param))
            {
                $result = false;
                throw new InvoiceException($this->getId().": required ".$param." is not set");
            }
        }
        return true;
    }


    /**
     * @param string $name
     * @param string $value
     * @param bool $maxLength
     * @param bool $isNumeric
     * @param bool $isDate
     * @throws InvoiceException
     */
    private function validateItem($name, $value, $maxLength = false, $isNumeric = false, $isDate = false)
    {
        try
        {
            if($maxLength)
                Validators::assertMaxLength($value, $maxLength);
            if($isNumeric)
                Validators::assertNumeric($value);
            if($isDate)
                Validators::assertDate($value);
        }
        catch(\InvalidArgumentException $e)
        {
            throw new InvoiceException($this->getId().": ".$name." - ".$e->getMessage(), 0, $e);
        }
    }

    private function removeSpaces($value)
    {
        return preg_replace('/\s+/', '', $value);
    }

    private function convertDate($date)
    {
        if($date instanceof DateTime)
            return $date->format("Y-m-d");

        return $date;
    }

    public function addItem(InvoiceItem $item)
    {
        $this->items[] = $item;
    }

    public function setNumberOrder($order)
    {
        $this->numberOrder = $order;
    }

    public function setVariableNumber($value)
    {
        $value = $this->removeSpaces($value);
        $this->validateItem('variable number', $value, 20, true);
        $this->varNum = $value;
    }

    public function setDateCreated($value)
    {
        $this->validateItem('date created', $value, false, false, true);
        $this->date = $this->convertDate($value);
    }

    public function setDateTax($value)
    {
        $this->validateItem('date tax', $value, false, false, true);
        $this->dateTax = $this->convertDate($value);
    }

    public function setDateAccounting($value)
    {
        $this->validateItem('date accounting', $value, false, false, true);
        $this->dateAccounting = $this->convertDate($value);
    }

    public function setDateDue($value)
    {
        $this->validateItem('date due', $value, false, false, true);
        $this->dateDue = $this->convertDate($value);
    }

    public function setDateOrder($value)
    {
        $this->validateItem('date order', $value, false, false, true);
        $this->dateOrder = $this->convertDate($value);
    }

    public function setText($value)
    {
        $this->validateItem('text', $value, 240);
        $this->text = $value;
    }

    public function setBank($value)
    {
        $this->validateItem('bank shortcut', $value, 19);
        $this->bankShortcut = $value;
    }

    /**
     * @param string $value
     * @throws InvoiceException
     */
    public function setPaymentType($value)
    {
        $allowed_payment_types = [
            self::PAYMENT_TYPE_FOREIGN_CURRENCY,
            self::PAYMENT_TYPE_CASH,
            self::PAYMENT_TYPE_OTHER,
            self::PAYMENT_TYPE_CREDIT_CARD,
            self::PAYMENT_TYPE_TRANSFER,
            self::PAYMENT_TYPE_TICKET,
            self::PAYMENT_TYPE_CHEQUE,
            self::PAYMENT_TYPE_ADVANCE,
            self::PAYMENT_TYPE_DELIVERY,
        ];

        if(is_null($value) || !in_array($value,$allowed_payment_types))
        {
            throw new InvoiceException($this->getId().": payment type $value is not supported. Use one of these: ".implode(",", $allowed_payment_types));
        }
        $this->paymentType = $value;
    }

    /** @deprecated */
    public function setPaymentTypeCzech($value)
    {
        return $this->setPaymentTypeString($value);
    }

    public function setPaymentTypeString($value)
    {
        $this->validateItem('payment type string', $value, 20);
        $this->paymentTypeString = $value;
    }

    public function setAccounting($value)
    {
        $this->validateItem('accounting', $value, 19);
        $this->accounting = $value;
    }

    public function setNote($value)
    {
        $this->note = $value;
    }

    public function setContract($value)
    {
        $this->validateItem('contract', $value, 19);
        $this->contract = $value;
    }


    public function setCentre($value)
    {
        $this->validateItem('centre', $value, 19);
        $this->centre = $value;
    }


    public function setActivity($value)
    {
        $this->validateItem('activity', $value, 19);
        $this->activity = $value;
    }


    public function setSymbolicNumber($value)
    {
        $value = $this->removeSpaces($value);
        $this->validateItem('symbolic number', $value, 20, true);
        $this->symbolicNumber = $value;
    }

    /**
     * Set price in nullable VAT
     * @param float $priceNone
     */
    public function setPriceNone($priceNone)
    {
        $this->priceNone = $priceNone;
    }

    /**
     * Set price without VAT (DPH - 15%)
     * @param float $priceLow
     */
    public function setPriceLow($priceLow)
    {
        $this->priceLow = $priceLow;
    }

    /**
     * Set only VAT (DPH - 15%)
     * @param float $priceLowVAT
     */
    public function setPriceLowVAT($priceLowVAT)
    {
        $this->priceLowVAT = $priceLowVAT;
    }

    /**
     * Set price with VAT (DPH - 15%)
     * @param float $priceLowSum
     */
    public function setPriceLowSum($priceLowSum)
    {
        $this->priceLowSum = $priceLowSum;
    }

    /**
     * Set price without VAT (DPH - 21%)
     * @param float $priceHigh
     */
    public function setPriceHigh($priceHigh)
    {
        $this->priceHigh = $priceHigh;
    }

    /**
     * Set only VAT (DPH - 21%)
     * @param float $priceHightVAT
     */
    public function setPriceHightVAT($priceHightVAT)
    {
        $this->priceHightVAT = $priceHightVAT;
    }

    /**
     * Set price with VAT (DPH - 21%)
     * @param float $priceHighSum
     */
    public function setPriceHighSum($priceHighSum)
    {
        $this->priceHighSum = $priceHighSum;
    }


    public function setForeignCurrency(string $currency, float $rate, int $amount = 1, ?float $foreignCurrencyPriceSum = null)
    {
        $this->foreignCurrency = $currency;
        $this->rate = $rate;
        $this->amount = $amount;
        $this->foreignCurrencyPriceSum = $foreignCurrencyPriceSum;
    }

    public function setProviderIdentity($value)
    {
        if(isset($value['company']))
        {
            $this->validateItem('provider - company', $value['company'], 96);
        }
        if(isset($value['ico']))
        {
            $value['ico'] = $this->removeSpaces($value['ico']);
            $this->validateItem('provider - ico', $value['ico'], 15, true);
        }
        if(isset($value['street']))
        {
            $this->validateItem('provider - street', $value['street'], 64);
        }
        if(isset($value['zip']))
        {
            $value['zip'] = $this->removeSpaces($value['zip']);
            $this->validateItem('provider - zip', $value['zip'], 15, true);
        }
        if(isset($value['city']))
        {
            $this->validateItem('provider - city', $value['city'], 45);
        }

        $this->myIdentity = $value;
    }

    /**
     * @param array $customerAddress
     * @param null $identity
     * @param array $shippingAddress
     * @throws InvoiceException
     */
    public function createCustomerAddress(array $customerAddress, $identity = null, array $shippingAddress = [])
    {
        try
        {
            $address = new Address(
                new \Pohoda\Object\Identity(
                    $identity, //identifikator zakaznika [pokud neni zadan, neprovede se import do adresare]
                    new \Pohoda\Object\Address($customerAddress), //adresa zakaznika
                    new \Pohoda\Object\Address($shippingAddress) //pripadne dodaci adresa
                )
            );

            $this->setCustomerAddress($address);

            return $address;
        }
        catch(\InvalidArgumentException $e)
        {
            throw new InvoiceException($this->getId().": ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Get shop identity
     * @return array
     */
    public function getProviderIdentity()
    {
        return $this->myIdentity;
    }


    /**
     * @param Address $address
     */
    public function setCustomerAddress(Address $address)
    {
        $this->customerAddress = $address;
    }

    /** storno */
    public function cancelDocument($id)
    {
        $this->cancel = $id;
    }

    public function cancelNumber($number)
    {
        $this->cancelNumber = $number;
    }


    public function export(SimpleXMLElement $xml)
    {
        $xmlInvoice = $xml->addChild("inv:invoice", null, self::NS);
        $xmlInvoice->addAttribute('version', "2.0");

        if($this->cancel)
        {
            $xmlInvoice
                ->addChild('cancelDocument')
                ->addChild('sourceDocument', null, Export::NS_TYPE)
                ->addChild('number', $this->cancel, Export::NS_TYPE);
            $this->exportCancelHeader($xmlInvoice->addChild("inv:invoiceHeader", null, self::NS));
        }
        else
        {
            $this->exportHeader($xmlInvoice->addChild("inv:invoiceHeader", null, self::NS));
            if(!empty($this->items))
            {
                $this->exportDetail($xmlInvoice->addChild("inv:invoiceDetail", null, self::NS));
            }
            $this->exportSummary($xmlInvoice->addChild("inv:invoiceSummary", null, self::NS));
        }
    }

    private function exportCancelHeader(SimpleXMLElement $header)
    {
        $header->addChild("invoiceType", $this->type);
        $header->addChild("inv:text", $this->text);

        if($this->cancelNumber !== null)
        {
            $num = $header->addChild("inv:number");
            $num->addChild('typ:ids', $this->cancelNumber, Export::NS_TYPE);
        }
    }

    private function exportHeader(SimpleXMLElement $header)
    {

        $header->addChild("inv:invoiceType", $this->type);
        $num = $header->addChild("inv:number");
        $num->addChild('typ:numberRequested', $this->getId(), Export::NS_TYPE);

        $header->addChild("inv:symVar", $this->varNum);

        $header->addChild("inv:date", $this->date);
        if(!is_null($this->dateTax))
            $header->addChild("inv:dateTax", $this->dateTax);
        if(!is_null($this->dateDue))
            $header->addChild("inv:dateDue", $this->dateDue);
        if(!is_null($this->dateAccounting))
            $header->addChild("inv:dateAccounting", $this->dateAccounting);


        $classification = $header->addChild("inv:classificationVAT");
        if($this->withVAT)
        {
            //tuzemske plneni
            $classification->addChild('typ:classificationVATType', 'inland', Export::NS_TYPE);
        }
        else
        {
            //nezahrnovat do dph
            $classification->addChild('typ:ids', 'UN', Export::NS_TYPE);
            $classification->addChild('typ:classificationVATType', 'nonSubsume', Export::NS_TYPE);
        }

        if(!is_null($this->accounting))
        {
            $accounting = $header->addChild("inv:accounting");
            $accounting->addChild('typ:ids', $this->accounting, Export::NS_TYPE);
        }

        $header->addChild("inv:text", $this->text);

        $paymentType = $header->addChild("inv:paymentType");
        $paymentType->addChild('typ:paymentType', $this->paymentType, Export::NS_TYPE);
        if(!is_null($this->paymentTypeString))
        {
            $paymentType->addChild('typ:ids', $this->paymentTypeString, Export::NS_TYPE);
        }

        $account = $header->addChild("inv:account");
        $account->addChild('typ:ids', $this->bankShortcut, Export::NS_TYPE);

        if(isset($this->note))
        {
            $header->addChild("inv:note", $this->note);
        }

        $header->addChild("inv:intNote", 'Tento doklad byl vytvořen importem přes XML.');

        if(isset($this->contract))
        {
            $contract = $header->addChild("inv:contract");
            $contract->addChild('typ:ids', $this->contract, Export::NS_TYPE);
        }

        if(isset($this->centre))
        {
            $centre = $header->addChild("inv:centre");
            $centre->addChild('typ:ids', $this->centre, Export::NS_TYPE);
        }

        if(isset($this->activity))
        {
            $activity = $header->addChild("inv:activity");
            $activity->addChild('typ:ids', $this->activity, Export::NS_TYPE);
        }

        $header->addChild("inv:symConst", $this->symbolicNumber);

        /** Pouze pri exportu z pohody.
         * $liq = $header->addChild("inv:liquidation");
         * $liq->addChild('typ:amountHome', $this->priceTotal, Export::NS_TYPE);
         */

        $myIdentity = $header->addChild("inv:myIdentity");
        $this->exportAddress($myIdentity, $this->myIdentity);

        $partnerIdentity = $header->addChild("inv:partnerIdentity");
        $this->customerAddress->exportAddress($partnerIdentity);

        if(isset($this->numberOrder))
        {
            $header->addChild("inv:numberOrder", $this->numberOrder);
        }

        if(isset($this->dateOrder))
        {
            $header->addChild("inv:dateOrder", $this->dateOrder);
        }
    }

    private function exportDetail(SimpleXMLElement $detail)
    {
        foreach($this->items AS $product)
        {
            /** @var InvoiceItem $product */
            $item = $detail->addChild("inv:invoiceItem");
            $item->addChild("inv:text", $product->getText());
            $item->addChild("inv:quantity", $product->getQuantity());
            $item->addChild("inv:unit", $product->getUnit());
            $item->addChild("inv:coefficient", $product->getCoefficient());
            $item->addChild("inv:payVAT", $product->isPayVAT() ? 'true' : 'false');
            if($product->getRateVAT())
                $item->addChild("inv:rateVAT", $product->getRateVAT());
            if($product->getDiscountPercentage())
                $item->addChild("inv:discountPercentage", $product->getDiscountPercentage());

            if($this->foreignCurrency === null)
            {
                $hc = $item->addChild("inv:homeCurrency");
                if($product->getUnitPrice())
                    $hc->addChild("typ:unitPrice", $product->getUnitPrice(), Export::NS_TYPE);
                if($product->getPrice())
                    $hc->addChild("typ:price", $product->getPrice(), Export::NS_TYPE);
                if($product->getPriceVAT())
                    $hc->addChild("typ:priceVAT", $product->getPriceVAT(), Export::NS_TYPE);
            }
            else
            {
                $fc = $item->addChild('inv:foreignCurrency');
                if($product->getForeignUnitPrice())
                    $fc->addChild("typ:unitPrice", $product->getForeignUnitPrice(), Export::NS_TYPE);
                if($product->getForeignPrice())
                    $fc->addChild("typ:price", $product->getForeignPrice(), Export::NS_TYPE);
                if($product->getForeignPriceVAT())
                    $fc->addChild("typ:priceVAT", $product->getForeignPriceVAT(), Export::NS_TYPE);
            }

            $item->addChild("inv:note", $product->getNote());
            $item->addChild("inv:code", $product->getCode());
            $item->addChild("inv:guarantee", $product->getGuarantee());
            $item->addChild("inv:guaranteeType", $product->getGuaranteeType());

            //info o skladove polozce
            if($product->getStockItem())
            {
                $stock = $item->addChild("inv:stockItem");
                $stockItem = $stock->addChild("typ:stockItem", null, Export::NS_TYPE);
                $stockItem->addChild("typ:ids", $product->getStockItem(), Export::NS_TYPE);
            }
        }
    }

    private function exportAddress(SimpleXMLElement $xml, Array $data, $type = "address")
    {

        $address = $xml->addChild('typ:'.$type, null, Export::NS_TYPE);

        if(isset($data['company']))
        {
            $address->addChild('typ:company', $data['company']);
        }

        if(isset($data['name']))
        {
            $address->addChild('typ:name', $data['name']);
        }

        if(isset($data['division']))
        {
            $address->addChild('typ:division', $data['division']);
        }

        if(isset($data['city']))
        {
            $address->addChild('typ:city', $data['city']);
        }

        if(isset($data['street']))
        {
            $address->addChild('typ:street', $data['street']);
        }

        if(isset($data['number']))
        {
            $address->addChild('typ:number', $data['number']);
        }

        if(isset($data['country']))
        {
            $address->addChild('typ:country')->addChild('typ:ids', $data['country']);
        }

        if(isset($data['zip']))
        {
            $address->addChild('typ:zip', $data['zip']);
        }

        if(isset($data['ico']))
        {
            $address->addChild('typ:ico', $data['ico']);
        }

        if(isset($data['dic']))
        {
            $address->addChild('typ:dic', $data['dic']);
        }

        if(isset($data['phone']))
        {
            $address->addChild('typ:mobilPhone', $data['phone']);
        }

        if(isset($data['email']))
        {
            $address->addChild('typ:email', $data['email']);
        }
    }

    private function exportSummary(SimpleXMLElement $summary)
    {

        $summary->addChild('inv:roundingDocument', $this->roundingDocument); //matematicky na koruny
        $summary->addChild('inv:roundingVAT', $this->roundingVAT);

        $hc = $summary->addChild("inv:homeCurrency");
        if(is_null($this->priceNone) === false)
            $hc->addChild('typ:priceNone', $this->priceNone, Export::NS_TYPE); //cena v nulove sazbe dph
        if(is_null($this->priceLow) === false)
            $hc->addChild('typ:priceLow', $this->priceLow, Export::NS_TYPE); //cena bez dph ve snizene sazbe (15)
        if(is_null($this->priceLowVAT) === false)
            $hc->addChild('typ:priceLowVAT', $this->priceLowVAT, Export::NS_TYPE); //dph ve snizene sazbe
        if(is_null($this->priceLowSum) === false)
            $hc->addChild('typ:priceLowSum', $this->priceLowSum, Export::NS_TYPE); //s dph ve snizene sazbe
        if(is_null($this->priceHigh) === false)
            $hc->addChild('typ:priceHigh', $this->priceHigh, Export::NS_TYPE); //cena bez dph ve zvysene sazbe (21)
        if(is_null($this->priceHightVAT) === false)
            $hc->addChild('typ:priceHighVAT', $this->priceHightVAT, Export::NS_TYPE);
        if(is_null($this->priceHighSum) === false)
            $hc->addChild('typ:priceHighSum', $this->priceHighSum, Export::NS_TYPE);

        if($this->foreignCurrency !== null)
        {
            $fc = $summary->addChild('inv:foreignCurrency');
            $fc->addChild('typ:currency', null, Export::NS_TYPE)->addChild('typ:ids', $this->foreignCurrency, Export::NS_TYPE);
            $fc->addChild('typ:rate', $this->rate, Export::NS_TYPE);
            $fc->addChild('typ:amount', $this->amount, Export::NS_TYPE);
            if($this->foreignCurrencyPriceSum !== null)
            {
                $fc->addChild('typ:priceSum', $this->foreignCurrencyPriceSum, Export::NS_TYPE);
            }
        }

        $round = $hc->addChild('typ:round', null, Export::NS_TYPE);
        $round->addChild('typ:priceRound', 0, Export::NS_TYPE); //Celková suma zaokrouhleni

    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param string $value
     */
    public function setType($value)
    {
        if(!in_array(
            $value, [
            self::TYPE_ISSUED_INVOICE,
            self::TYPE_ISSUED_CREDIT_NOTICE,
            self::TYPE_ISSUED_DEBIT_NOTE,
            self::TYPE_ISSUED_ADVANCE_INVOICE,
            self::TYPE_RECEIVABLE,
            self::TYPE_ISSUED_PROFORMA_INVOICE,
            self::TYPE_PENALTY,
            self::TYPE_ISSUED_CORRECTIVE_TAX,
            self::TYPE_RECEIVED_INVOICE,
            self::TYPE_RECEIVED_CREDIT_NOTICE,
            self::TYPE_RECEIVED_DEBIT_NOTE,
            self::TYPE_RECEIVED_ADVANCE_INVOICE,
            self::TYPE_COMMITMENT,
            self::TYPE_RECEIVED_PROFORMA_INVOICE,
            self::TYPE_RECEIVED_CORRECTIVE_TAX,
        ], true)) throw new InvoiceException('Invalid invoice type: '.print_r($value, true));
        $this->type = $value;
    }

    /**
     * BC
     * @deprecated Use setters instead
     * @inheritDoc
     */
    public function __set($name, $value)
    {
        switch($name)
        {
            case 'type':
                $this->setType($value);
                break;
            default:
                throw new \LogicException('Invalid attribute $'.$name);
        }
    }

    /**
     * BC
     * @deprecated Use getters instead
     * @inheritDoc
     */
    public function __get($name)
    {
        switch($name)
        {
            case 'type':
                return $this->getType();
        }
        throw new \LogicException('Invalid attribute $'.$name);
    }
}

class InvoiceException extends \Exception
{
}
