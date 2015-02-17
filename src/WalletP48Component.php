<?php

namespace app\components;


use Yii;
use yii\base\Component;


class WalletP48Component extends Component
{

    private $id_ekb = null;
    private $ldap = null;
    private $session = null;
    private $cards = null;
    private $error = null;

    private $purse_id;
    private $fio;
    private $phone;
    private $bank;
    private $inn;

    /**
     * @param $params
     */
    public function setParams($params)
    {
        $this->cards = null;

        foreach ($params as $key => $value) {
            $this->$key = $value;
        }
    }

    public function getValue($param)
    {
        if (isset($this->$param)) {
            $result = $this->$param;

        } else {
            $result = null;
        }

        return $result;
    }

    /**
     * Получение карт клиента
     *
     * @return bool
     */
    public function getPurse()
    {
        // Добавляем пароль и сессию сотрудника в заголовок
        Yii::$app->CurlComponent->additional_headers = [
            'Content-Type: application/xml',
            'Accept: application/xml',
            'Authorization: Basic ' . Yii::$app->params['wallet_p48']['pass'],
            'wfsid: ' . $this->session
        ];

        // Берем url запроса
        Yii::$app->CurlComponent->url = Yii::$app->params['wallet_p48']['url'];

        // Ответ сервиса не критичен
        Yii::$app->CurlComponent->critical = false;

        // Генерируем уникальный референс
        $request_reference = substr(strtoupper(md5(uniqid(rand(), true))), 8);

        // Формируем тело запроса
        Yii::$app->CurlComponent->request = '
            <wfm:wfPurseTwoRequest  xmlns:wfm="uri:ua.pb.p48.wf.module.purse.api.model">
                <reqMRef>' . $request_reference . '</reqMRef>
                <reqMId>CASH</reqMId>
                <reqCUser>'. $this->ldap .'</reqCUser>
                <reqBank>PB</reqBank>
                <ekbId>'. $this->id_ekb .'</ekbId>
            </wfm:wfPurseTwoRequest>
        ';

        // Отправляем запрос на выполнение
        $result = Yii::$app->CurlComponent->query();

        $i = 0;
        while($i <= 10) {

            sleep(1);

            // Отправляем запрос на выполнение
            $result = Yii::$app->CurlComponent->query();

            // Если успешен запрос, получаем баланс
            if (!is_object($result) or (string)$result->reqPrState == 'e') {
                $this->error = (string)$result->reqPrMess . ' Code: ' . (string)$result->reqPrCode;
                $result = false;
                break;

            } else if ((string)$result->reqPrState == 'r' and (string)$result->bpState == 'r') {
                break;
            }

            $i++;
        }

        return $result;
    }


    public function getCards()
    {
        if (!$this->cards and !is_array($this->cards)) {
            $response = $this->getPurse();

            if (is_object($response)) {
                $all_cards = $response->wfPurse->wfPurseCardList->wfPurseCard;

            } else {
                return [];
            }

            // id кошелька клиента
            $this->purse_id = (string)$response->wfPurse->purseId;
            // ФИО клиента
            $this->fio = (string)$response->wfPurse->fio;
            // Номер телефона клиента
            $this->phone = (string)$response->wfPurse->phone;
            // Банк клиента
            $this->bank = (string)$response->wfPurse->bank;
            // ИНН клиента
            $this->inn = (string)$response->wfPurse->inn;

            $cards = array();

            // Получаем необходимые блоки данных в разрезе карт и счетов
            foreach ($all_cards as $card) {

                $cards[(string)$card->pan]['wfPurseCard'] =
                    $this->_getPurseData($response->wfPurse, 'wfPurseCard', 'pan', (string)$card->pan);

                $cards[(string)$card->pan]['wfContractCard'] =
                    $this->_getPurseData($response->wfPurse, 'wfContractCard', 'pan', (string)$card->pan);

                foreach ($cards[(string)$card->pan]['wfContractCard'] as $wf_contract_card) {

                    if ((string)$wf_contract_card->canState == 3) {
                        $cards[(string)$card->pan]['wfPurseContract'] =
                            $this->_getPurseData(
                                $response->wfPurse,
                                'wfPurseContract',
                                'refContract',
                                (string)$wf_contract_card->refContract
                            );
                    }
                }

                if (isset($cards[(string)$card->pan]['wfPurseContract'])) {
                    foreach ($cards[(string)$card->pan]['wfPurseContract'] as $wf_purse_contract) {
                       $cards[(string)$card->pan]['twoAccount'] =
                           $this->_getPurseData($response->wfPurse, 'twoAccount', 'can', (string)$wf_purse_contract->can);
                    }

                    $cards[(string)$card->pan]['twoCard'] =
                        $this->_getPurseData($response->wfPurse, 'twoCard', 'pan', (string)$card->pan);

                    $cards[(string)$card->pan]['authCard'] =
                        $this->_getPurseData($response->wfPurse, 'authCard', 'pan', (string)$card->pan);

                } else {
                    unset($cards[(string)$card->pan]);
                }
            }

            $this->cards = $cards;
        }

        return $this->cards;
    }


    /**
     * Получаем данные по кошельку
     *
     * @param $purse - Кошелек
     * @param $search_branch - Искомая ветка
     * @param $search_param - Искомый параметр
     * @param string $filter - Фильтр по которому искать
     * @return array - Результат выборки
     */
    private function _getPurseData($purse, $search_branch, $search_param, $filter)
    {
        $result = array();

        foreach ($purse->xpath('//'.$search_branch) as $character) {
            if ((string)$character->$search_param == $filter) {
                $result[] = $character;
            }
        }

        return $result;
    }


    public function filterByStatus(array $cards = null)
    {
        if (is_null($cards)) {
            $cards = $this->getCards();
        }

        foreach ($cards as $card_number => $card) {

            if (isset($card['twoCard'][0]->crdStat)) {

                $card_status = (string)$card['twoCard'][0]->crdStat;

                if (
                    $card_status == 2
                    || $card_status == 3
                    || $card_status == 4
                    || $card_status == 8
                    || $card_status == 9
                ) {
                    unset($cards[$card_number]);
                }
            }
        }

        return $cards;
    }


    public function filterByTrusted(array $cards = null)
    {
        if (is_null($cards)) {
            $cards = $this->getCards();
        }

        foreach ($cards as $card_number => $card) {

            if ((string)$card['wfPurseCard'][0]->purseId != $this->purse_id) {
                unset($cards[$card_number]);
            }
        }

        return $cards;
    }


    public function filterByContractype(array $contractypes, $allowed = false, array $cards = null)
    {
        if (is_null($cards)) {
            $cards = $this->getCards();
        }

        foreach ($cards as $card_number => $card) {

            if (
                (
                    $allowed
                    and !in_array((string)$card['wfPurseContract'][0]->contractType, $contractypes)
                ) or (
                    !$allowed
                    and in_array((string)$card['wfPurseContract'][0]->contractType, $contractypes)
                )
            ) {
                unset($cards[$card_number]);
            }
        }

        return $cards;
    }


    public function filterByCurrency($currency, array $cards = null)
    {
        if (is_null($cards)) {
            $cards = $this->getCards();
        }

        foreach ($cards as $card_number => $card) {
            if ((string)$card['wfPurseContract'][0]->currency != $currency) {
                unset($cards[$card_number]);
            }
        }

        return $cards;
    }


    public function filterByDate($forward = true, array $cards = null)
    {
        if (is_null($cards)) {
            $cards = $this->getCards();
        }

        $cards_sort = [];

        foreach ($cards as $card_number => $card) {

            $date = \DateTime::createFromFormat('Y-m-d\TH:i:s\+??:??', (string)$card['wfPurseContract'][0]->createDate);
            $unix_date = $date->getTimestamp();
            $cards_sort[] = ['card_number' => $card_number, 'unix_date' => $unix_date];
        }

        if (count($cards_sort)) {
            asort($cards_sort);

            if ($forward) {
                $sought_card = array_pop($cards_sort);
            } else {
                $sought_card = array_shift($cards_sort);
            }

            foreach ($cards as $key => $value) {
                if ($key != $sought_card['card_number']) {
                    unset($cards[$key]);
                }
            }
        }

        return $cards;
    }
}