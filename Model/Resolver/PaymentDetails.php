<?php

namespace Paytrail\PaymentServiceGraphQl\Model\Resolver;

use Magento\Checkout\Model\Session;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\Command\CommandManagerPoolInterface;
use Magento\Sales\Model\Order;
use Paytrail\PaymentService\Exceptions\CheckoutException;
use Paytrail\PaymentService\Gateway\Config\Config;
use Paytrail\PaymentService\Model\ProviderForm;
use Paytrail\PaymentService\Model\Receipt\ProcessService;
use Paytrail\PaymentService\Model\Ui\DataProvider\PaymentProvidersData;
use Paytrail\SDK\Response\PaymentResponse;
use Psr\Log\LoggerInterface;

class PaymentDetails implements ResolverInterface
{

    /**
     * @var string
     */
    private string $errorMsg;
    /**
     * Class constructor.
     *
     * @param Session $checkoutSession
     * @param LoggerInterface $logger
     * @param CommandManagerPoolInterface $commandManagerPool
     * @param PaymentProvidersData $paymentProvidersData
     * @param ProviderForm $providerForm
     * @param ProcessService $processService
     */
    public function __construct(
        private readonly Session                     $checkoutSession,
        private readonly LoggerInterface             $logger,
        private readonly CommandManagerPoolInterface $commandManagerPool,
        private readonly PaymentProvidersData        $paymentProvidersData,
        private readonly ProviderForm                $providerForm,
        private readonly ProcessService              $processService
    ) {
    }

    /**
     * Resolve function.
     *
     * @param Field $field
     * @param ContextInterface $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     *
     * @return string[]
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null): array
    {
        $result = [
            'payment_url'       => '',
            'error'             => '',
            'payment_form_html' => ''
        ];

        try {
            $order = $this->checkoutSession->getLastRealOrder();
            if ($order->getPayment()->getMethod() == Config::CODE) {
                $paytrailPayment        = $this->getPaytrailPayment($order);
                $result['payment_url']  = $paytrailPayment->getHref();
                $result['payment_form'] = $this->getForm($paytrailPayment, $order);
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Get Paytrail payment.
     *
     * @param Order $order
     *
     * @return PaymentResponse
     * @throws NotFoundException
     * @throws CommandException
     * @throws CheckoutException
     */
    private function getPaytrailPayment(Order $order): PaymentResponse
    {
        $commandExecutor = $this->commandManagerPool->get('paytrail');
        $response        = $commandExecutor->executeByCode(
            'payment',
            null,
            [
                'order'          => $order,
                'payment_method' => $this->paymentProvidersData->getIdWithoutIncrement(
                    $order->getPayment()->getAdditionalInformation()['provider'] ?? ''
                )
            ]
        );

        if ($response['error']) {
            $this->errorMsg = ($response['error']);
            $this->processService->processError($response['error']);
        }

        return $response["data"];
    }

    /**
     * Get form parameters for selected provider.
     *
     * @param PaymentResponse $response
     * @param Order $order
     *
     * @return array|null
     */
    private function getForm(PaymentResponse $response, Order $order): ?array
    {
        $paymentMethodId = $this->paymentProvidersData->getIdWithoutIncrement(
            $order->getPayment()->getAdditionalInformation()['provider'] ?? ''
        );

        $cardType = $this->paymentProvidersData->getCardType(
            $order->getPayment()->getAdditionalInformation()['provider'] ?? ''
        );

        if (!$paymentMethodId) {
            return null;
        }

        return $this->providerForm->getFormParams($response, $paymentMethodId, $cardType);
    }
}
