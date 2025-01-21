<?php
/**
 * O2TI Sigep Web Carrier.
 *
 * Copyright © 2025 O2TI. All rights reserved.
 *
 * @author    Bruno Elisei <brunoelisei@o2ti.com>
 * @license   See LICENSE for license details.
 */

namespace O2TI\SigepWebCarrier\Model;

use O2TI\SigepWebCarrier\Model\Carrier as CorreiosCarrier;
use O2TI\SigepWebCarrier\Model\TrackingProcessor;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\Order\Email\Sender\ShipmentCommentSender;
use Magento\Framework\Escaper;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Psr\Log\LoggerInterface;
use DateTime;

/**
 * Tracking Status for Cron and Model.
 */
class TrackingStatus
{
    /**
     * @var OrderRepository
     */
    protected $orderRepo;

    /**
     * @var TrackingProcessor
     */
    protected $trackingProcessor;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ShipmentCommentSender
     */
    protected $commentSender;

    /**
     * @var Escaper
     */
    protected $escaper;

    /**
     * @var HistoryFactory
     */
    protected $historyFactory;

    /**
     * Constructor.
     *
     * @param OrderRepository $orderRepo
     * @param TrackingProcessor $trackingProcessor
     * @param LoggerInterface $logger
     * @param ShipmentCommentSender $commentSender
     * @param Escaper $escaper
     * @param HistoryFactory $historyFactory
     */
    public function __construct(
        OrderRepository $orderRepo,
        TrackingProcessor $trackingProcessor,
        LoggerInterface $logger,
        ShipmentCommentSender $commentSender,
        Escaper $escaper,
        HistoryFactory $historyFactory
    ) {
        $this->orderRepo = $orderRepo;
        $this->trackingProcessor = $trackingProcessor;
        $this->logger = $logger;
        $this->commentSender = $commentSender;
        $this->escaper = $escaper;
        $this->historyFactory = $historyFactory;
    }

    /**
     * Process tracking status for an order
     *
     * @param Order $order
     * @throws \Exception
     * @return void
     */
    public function processOrder(Order $order)
    {
        try {
            foreach ($order->getShipmentsCollection() as $shipment) {
                $this->processShipmentTracks($shipment);
            }
        } catch (\Exception $exc) {
            $this->logError('Error processing order tracking', $exc, ['order_id' => $order->getId()]);
            throw $exc;
        }
    }

    /**
     * Process tracking numbers for a shipment
     *
     * @param Shipment $shipment
     * @return void
     */
    protected function processShipmentTracks(Shipment $shipment)
    {
        foreach ($shipment->getAllTracks() as $track) {
            if ($track->getCarrierCode() === CorreiosCarrier::CODE) {
                $trackingInfo = $this->trackingProcessor->getTrackingInfo($track->getTrackNumber());
                if ($trackingInfo) {
                    $this->updateShipmentStatus($shipment, $track->getTrackNumber(), $trackingInfo);
                }
            }
        }
    }

    /**
     * Update shipment and order status
     *
     * @param Shipment $shipment
     * @param string $trackNumber
     * @param array $trackingInfo
     * @return void
     */
    protected function updateShipmentStatus(Shipment $shipment, string $trackNumber, array $trackingInfo)
    {
        $comment = $this->formatStatusComment($trackNumber, $trackingInfo);
        $this->addShipmentComment($shipment, $comment);

        $status = $trackingInfo['status'];
        $this->updateOrderStatus($shipment->getOrder(), $comment, $status);
    }

    /**
     * Format status update comment
     *
     * @param string $trackNumber
     * @param array $trackingInfo
     * @return \Magento\Framework\Phrase
     */
    protected function formatStatusComment(string $trackNumber, array $trackingInfo): \Magento\Framework\Phrase
    {
        if (empty($trackingInfo['progressdetail'])) {
            // phpcs:ignore
            return __('Seu pedido foi encaminhado para coleta dos Correios, seu código de postagem é %1. Aguarde até 24 horas para acompanhar a entrega.', $trackNumber);
        }

        // Pega o primeiro evento (mais recente) do array de eventos
        $latestEvent = $trackingInfo['progressdetail'][0];
        
        // Monta a mensagem com base no status
        switch ($trackingInfo['status']) {
            case 'Delivered':
                return __(
                    'Seu pedido %1 foi entregue em %2 às %3 em %4.',
                    $trackNumber,
                    $latestEvent['deliverydate'],
                    $latestEvent['deliverytime'],
                    $latestEvent['deliverylocation']
                );
                
            default:
                return __(
                    'Atualização sobre sua entrega %1: %2. Localização: %3 em %4 %5',
                    $trackNumber,
                    $latestEvent['activity'],
                    $latestEvent['deliverylocation'],
                    $latestEvent['deliverydate'],
                    $latestEvent['deliverytime']
                );
        }
    }

    /**
     * Add comment to shipment
     *
     * @param Shipment $shipment
     * @param string $comment
     * @return void
     */
    protected function addShipmentComment(Shipment $shipment, string $comment)
    {
        $escapedComment = $this->escaper->escapeHtml($comment);
        $shipment->addComment($escapedComment, true, true);

        try {
            $shipment->save();

            $this->commentSender->send($shipment, true, $escapedComment);
        } catch (\Exception $exc) {
            $this->logError('Error sending shipment comment email', $exc, [
                'shipment_id' => $shipment->getId(),
                'order_id' => $shipment->getOrderId()
            ]);
        }
    }

    /**
     * Update order status and history
     *
     * @param Order $order
     * @param string $comment
     * @param string $status
     * @return void
     */
    protected function updateOrderStatus(Order $order, string $comment, string $status)
    {
        $history = $this->historyFactory->create()
            ->setParentId($order->getEntityId())
            ->setComment($comment)
            ->setStatus($status)
            ->setIsCustomerNotified(false)
            ->setEntityName('order');

        try {
            $order->addStatusHistory($history);
            $this->orderRepo->save($order);
        } catch (\Exception $exc) {
            $this->logError('Error updating order status', $exc);
        }
    }

    /**
     * Log error with context
     *
     * @param string $message
     * @param \Exception $exc
     * @param array $context
     * @return void
     */
    protected function logError(string $message, \Exception $exc, array $context = [])
    {
        $this->logger->error(
            $message . ': ' . $exc->getMessage(),
            array_merge($context, ['exception' => $exc])
        );
    }
}