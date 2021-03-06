<?php

namespace ShoppingFeed\Manager\Model\Marketplace\Order;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use ShoppingFeed\Manager\Api\Data\Account\StoreInterface;
use ShoppingFeed\Manager\Api\Data\Marketplace\Order\LogInterface;
use ShoppingFeed\Manager\Api\Data\Marketplace\Order\TicketInterface;
use ShoppingFeed\Manager\Api\Data\Marketplace\OrderInterface;
use ShoppingFeed\Manager\Api\Data\Marketplace\Order\LogInterfaceFactory as OrderLogInterfaceFactory;
use ShoppingFeed\Manager\Api\Data\Marketplace\Order\TicketInterfaceFactory as OrderTicketInterfaceFactory;
use ShoppingFeed\Manager\Api\Marketplace\Order\LogRepositoryInterface as OrderLogRepositoryInterface;
use ShoppingFeed\Manager\Api\Marketplace\Order\TicketRepositoryInterface as OrderTicketRepositoryInterface;
use ShoppingFeed\Manager\Model\ResourceModel\Marketplace\Order\Collection as OrderCollection;
use ShoppingFeed\Manager\Model\ResourceModel\Marketplace\Order\CollectionFactory as OrderCollectionFactory;
use ShoppingFeed\Manager\Model\Sales\Order\ConfigInterface as OrderConfigInterface;
use ShoppingFeed\Manager\Model\Sales\Order\SyncerInterface as SalesOrderSyncerInterface;
use ShoppingFeed\Manager\Model\Sales\Order\Shipment\Track as ShipmentTrack;
use ShoppingFeed\Manager\Model\Sales\Order\Shipment\Track\Collector as SalesShipmentTrackCollector;
use ShoppingFeed\Manager\Model\ShoppingFeed\Api\SessionManager as ApiSessionManager;
use ShoppingFeed\Sdk\Api\Order\OrderOperation as ApiOrderOperation;
use ShoppingFeed\Sdk\Api\Order\OrderResource as ApiOrder;
use ShoppingFeed\Sdk\Api\Task\TicketResource as ApiTicket;

class Manager
{
    const API_FILTER_ACKNOWLEDGEMENT = 'acknowledgment';
    const API_FILTER_CHANNEL_ID = 'channelId';
    const API_FILTER_REFERENCE = 'reference';
    const API_FILTER_SINCE = 'since';
    const API_FILTER_STATUS = 'status';

    const API_ACKNOWLEDGEMENT_STATUS_SUCCESS = 'success';
    const API_ACKNOWLEDGEMENT_STATUS_FAILURE = 'error';

    const API_ACKNOWLEDGED = 'acknowledged';
    const API_UNACKNOWLEDGED = 'unacknowledged';

    /**
     * @var ApiSessionManager
     */
    private $apiSessionManager;

    /**
     * @var OrderConfigInterface
     */
    private $orderGeneralConfig;

    /**
     * @var OrderCollectionFactory
     */
    private $orderCollectionFactory;

    /**
     * @var OrderLogInterfaceFactory
     */
    private $orderLogFactory;

    /**
     * @var OrderLogRepositoryInterface
     */
    private $orderLogRepository;

    /**
     * @var OrderTicketInterfaceFactory
     */
    private $orderTicketFactory;

    /**
     * @var OrderTicketRepositoryInterface
     */
    private $orderTicketRepository;

    /**
     * @var SalesShipmentTrackCollector
     */
    private $salesShipmentTrackCollector;

    /**
     * @param ApiSessionManager $apiSessionManager
     * @param OrderConfigInterface $orderGeneralConfig
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param OrderLogInterfaceFactory $orderLogFactory
     * @param OrderLogRepositoryInterface $orderLogRepository
     * @param OrderTicketInterfaceFactory $orderTicketFactory
     * @param OrderTicketRepositoryInterface $orderTicketRepository
     * @param SalesShipmentTrackCollector $salesShipmentTrackCollector
     */
    public function __construct(
        ApiSessionManager $apiSessionManager,
        OrderConfigInterface $orderGeneralConfig,
        OrderCollectionFactory $orderCollectionFactory,
        OrderLogInterfaceFactory $orderLogFactory,
        OrderLogRepositoryInterface $orderLogRepository,
        OrderTicketInterfaceFactory $orderTicketFactory,
        OrderTicketRepositoryInterface $orderTicketRepository,
        SalesShipmentTrackCollector $salesShipmentTrackCollector
    ) {
        $this->apiSessionManager = $apiSessionManager;
        $this->orderGeneralConfig = $orderGeneralConfig;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->orderLogFactory = $orderLogFactory;
        $this->orderLogRepository = $orderLogRepository;
        $this->orderTicketFactory = $orderTicketFactory;
        $this->orderTicketRepository = $orderTicketRepository;
        $this->salesShipmentTrackCollector = $salesShipmentTrackCollector;
    }

    /**
     * @param StoreInterface $store
     * @return string[]
     */
    public function getSyncableShoppingFeedStatuses(StoreInterface $store)
    {
        $statuses = [];

        $statusActions = [
            OrderInterface::STATUS_REFUSED => $this->orderGeneralConfig->getOrderRefusalSyncingAction($store),
            OrderInterface::STATUS_CANCELLED => $this->orderGeneralConfig->getOrderCancellationSyncingAction($store),
            OrderInterface::STATUS_REFUNDED => $this->orderGeneralConfig->getOrderRefundSyncingAction($store),
        ];

        foreach ($statusActions as $status => $action) {
            if (SalesOrderSyncerInterface::SYNCING_ACTION_NONE !== $action) {
                $statuses[] = $status;
            }
        }

        return $statuses;
    }

    /**
     * @param StoreInterface $store
     * @param int $channelId
     * @param string $reference
     * @return ApiOrder|null
     * @throws LocalizedException
     */
    public function getStoreImportableApiOrderByChannelAndReference(StoreInterface $store, $channelId, $reference)
    {
        if (empty($channelId) || empty($reference)) {
            return null;
        }

        $apiStore = $this->apiSessionManager->getStoreApiResource($store);

        $orders = $apiStore->getOrderApi()
            ->getAll(
                [
                    self::API_FILTER_ACKNOWLEDGEMENT => self::API_UNACKNOWLEDGED,
                    self::API_FILTER_CHANNEL_ID => (int) $channelId,
                    self::API_FILTER_REFERENCE => trim($reference),
                ]
            );

        $singleOrder = null;

        foreach ($orders as $order) {
            if ($order->getReference() === $reference) {
                if (null === $singleOrder) {
                    $singleOrder = $order;
                } else {
                    $singleOrder = null;
                    break;
                }
            }
        }

        return $singleOrder;
    }

    /**
     * @param StoreInterface $store
     * @return ApiOrder[]
     * @throws LocalizedException
     */
    public function getStoreImportableApiOrders(StoreInterface $store)
    {
        $apiStore = $this->apiSessionManager->getStoreApiResource($store);

        return $apiStore->getOrderApi()
            ->getAll(
                [
                    self::API_FILTER_ACKNOWLEDGEMENT => self::API_UNACKNOWLEDGED,
                    self::API_FILTER_SINCE => $this->orderGeneralConfig->getOrderImportFromDate($store),
                ]
            );
    }

    /**
     * @param StoreInterface $store
     * @param int|null $maximumCount
     * @return OrderInterface[]
     */
    public function getStoreImportableOrders(StoreInterface $store, $maximumCount = null)
    {
        $orderCollection = $this->orderCollectionFactory->create();

        $orderCollection->addNonImportedFilter();
        $orderCollection->addImportableFilter();
        $orderCollection->addCreatedFromDateFilter($this->orderGeneralConfig->getOrderImportFromDate($store));
        $orderCollection->addStoreIdFilter($store->getId());

        if (null !== $maximumCount) {
            $orderCollection->setCurPage(1);
            $orderCollection->setPageSize($maximumCount);
        }

        $orderCollection->load();

        return $orderCollection->getItems();
    }

    /**
     * @param StoreInterface $store
     * @return ApiOrder[]
     * @throws LocalizedException
     */
    public function getStoreSyncableApiOrders(StoreInterface $store)
    {
        $apiStore = $this->apiSessionManager->getStoreApiResource($store);
        $statuses = $this->getSyncableShoppingFeedStatuses($store);

        if (empty($statuses)) {
            return [];
        }

        return $apiStore->getOrderApi()
            ->getAll(
                [
                    self::API_FILTER_STATUS => $statuses,
                    self::API_FILTER_ACKNOWLEDGEMENT => self::API_ACKNOWLEDGED,
                    self::API_FILTER_SINCE => $this->orderGeneralConfig->getOrderSyncingFromDate($store),
                ]
            );
    }

    /**
     * @param StoreInterface $store
     * @param int|null $maximumCount
     * @return OrderInterface[]
     */
    public function getStoreSyncableOrders(StoreInterface $store, $maximumCount = null)
    {
        $statuses = $this->getSyncableShoppingFeedStatuses($store);

        if (empty($statuses)) {
            return [];
        }

        $orderCollection = $this->orderCollectionFactory->create();

        $orderCollection->addImportedFilter();
        $orderCollection->addShoppingFeedStatusFilter($statuses);
        $orderCollection->addCreatedFromDateFilter($this->orderGeneralConfig->getOrderSyncingFromDate($store));
        $orderCollection->addStoreIdFilter($store->getId());

        if (null !== $maximumCount) {
            $orderCollection->setCurPage(1);
            $orderCollection->setPageSize($maximumCount);
        }

        $orderCollection->load();

        return $orderCollection->getItems();
    }

    /**
     * @param OrderInterface $order
     * @param ApiTicket $apiTicket
     * @param string $action
     * @throws CouldNotSaveException
     */
    private function registerOrderApiTicket(OrderInterface $order, ApiTicket $apiTicket, $action)
    {
        $ticket = $this->orderTicketFactory->create();
        $ticket->setShoppingFeedTicketId(trim($apiTicket->getId()));
        $ticket->setOrderId($order->getId());
        $ticket->setAction($action);
        $ticket->setStatus(TicketInterface::STATUS_HANDLED);
        $this->orderTicketRepository->save($ticket);
    }

    /**
     * @param OrderInterface $order
     * @param string $storeReference
     * @param string $action
     * @param StoreInterface $store
     * @throws \Exception
     */
    private function notifyStoreOrderImportResult(
        OrderInterface $order,
        $storeReference,
        $action,
        StoreInterface $store
    ) {
        $apiStore = $this->apiSessionManager->getStoreApiResource($store);

        $operation = new ApiOrderOperation();
        $reference = $order->getMarketplaceOrderNumber();
        $channelName = $order->getMarketplaceName();

        if (TicketInterface::ACTION_ACKNOWLEDGE_SUCCESS === $action) {
            $apiStatus = self::API_ACKNOWLEDGEMENT_STATUS_SUCCESS;
        } else {
            $apiStatus = self::API_ACKNOWLEDGEMENT_STATUS_FAILURE;
        }

        $operation->acknowledge($reference, $channelName, $storeReference, $apiStatus);
        $result = $apiStore->getOrderApi()->execute($operation);
        $apiTickets = $result->getTickets();

        foreach ($apiTickets as $apiTicket) {
            try {
                $this->registerOrderApiTicket($order, $apiTicket, $action);
            } catch (\Exception $e) {
                $operation = new ApiOrderOperation();
                $operation->unacknowledge($reference, $channelName);
                $apiStore->getOrderApi()->execute($operation);
                throw $e;
            }

            break;
        }
    }

    /**
     * @param OrderInterface $order
     * @param string $salesIncrementId
     * @param StoreInterface $store
     * @throws \Exception
     */
    public function notifyStoreOrderImportSuccess(OrderInterface $order, $salesIncrementId, StoreInterface $store)
    {
        $this->notifyStoreOrderImportResult(
            $order,
            $salesIncrementId,
            TicketInterface::ACTION_ACKNOWLEDGE_SUCCESS,
            $store
        );
    }

    /**
     * @param OrderInterface $order
     * @param StoreInterface $store
     * @throws \Exception
     */
    public function notifyStoreOrderImportFailure(OrderInterface $order, StoreInterface $store)
    {
        $this->notifyStoreOrderImportResult(
            $order,
            (string) $order->getId(),
            TicketInterface::ACTION_ACKNOWLEDGE_FAILURE,
            $store
        );
    }

    /**
     * @param StoreInterface $store
     * @throws \Exception
     */
    public function notifyStoreOrderImportUpdates(StoreInterface $store)
    {
        $importedCollection = $this->orderCollectionFactory->create();
        $importedCollection->addStoreIdFilter($store->getId());
        $importedCollection->addNotifiableImportFilter();

        foreach ($importedCollection as $order) {
            $this->notifyStoreOrderImportSuccess(
                $order,
                trim($order->getDataByKey(OrderCollection::KEY_SALES_INCREMENT_ID)),
                $store
            );
        }
    }

    /**
     * @param OrderInterface $order
     * @param StoreInterface $store
     * @throws \Exception
     */
    public function notifyStoreOrderCancellation(OrderInterface $order, StoreInterface $store)
    {
        $apiStore = $this->apiSessionManager->getStoreApiResource($store);

        $operation = new ApiOrderOperation();
        $reference = $order->getMarketplaceOrderNumber();
        $channelName = $order->getMarketplaceName();

        $operation->cancel($reference, $channelName);
        $result = $apiStore->getOrderApi()->execute($operation);
        $apiTickets = $result->getTickets();

        foreach ($apiTickets as $apiTicket) {
            $this->registerOrderApiTicket($order, $apiTicket, TicketInterface::ACTION_CANCEL);
            break;
        }
    }

    /**
     * @param StoreInterface $store
     * @throws \Exception
     */
    public function notifyStoreOrderCancellationUpdates(StoreInterface $store)
    {
        $cancelledCollection = $this->orderCollectionFactory->create();
        $cancelledCollection->addStoreIdFilter($store->getId());
        $cancelledCollection->addNotifiableCancellationFilter();

        foreach ($cancelledCollection as $order) {
            $this->notifyStoreOrderCancellation($order, $store);
        }
    }

    /**
     * @param OrderInterface $order
     * @param ShipmentTrack $shipmentTrack
     * @param StoreInterface $store
     * @throws CouldNotSaveException
     * @throws LocalizedException
     * @throws \ShoppingFeed\Sdk\Order\Exception\TicketNotFoundException
     * @throws \ShoppingFeed\Sdk\Order\Exception\UnexpectedTypeException
     */
    public function notifyStoreOrderShipment(
        OrderInterface $order,
        ShipmentTrack $shipmentTrack,
        StoreInterface $store
    ) {
        $apiStore = $this->apiSessionManager->getStoreApiResource($store);

        $operation = new ApiOrderOperation();
        $reference = $order->getMarketplaceOrderNumber();
        $channelName = $order->getMarketplaceName();

        $operation->ship(
            $reference,
            $channelName,
            $shipmentTrack->getCarrierTitle(),
            $shipmentTrack->getTrackingNumber(),
            $shipmentTrack->getTrackingUrl()
        );

        $result = $apiStore->getOrderApi()->execute($operation);
        $apiTickets = $result->getTickets();

        foreach ($apiTickets as $apiTicket) {
            $this->registerOrderApiTicket($order, $apiTicket, TicketInterface::ACTION_SHIP);
            break;
        }
    }

    /**
     * @param StoreInterface $store
     * @throws \Exception
     */
    public function notifyStoreOrderShipmentUpdates(StoreInterface $store)
    {
        $shippedCollection = $this->orderCollectionFactory->create();
        $shippedCollection->addStoreIdFilter($store->getId());
        $shippedCollection->addIsFulfilledFilter(false);
        $shippedCollection->addNotifiableShipmentFilter();
        $shippedSalesOrderIds = [];

        foreach ($shippedCollection as $marketplaceOrder) {
            $shippedSalesOrderIds[] = (int) $marketplaceOrder->getSalesOrderId();
        }

        $salesShipmentTracks = $this->salesShipmentTrackCollector->getOrdersShipmentTracks($shippedSalesOrderIds);

        foreach ($shippedCollection as $marketplaceOrder) {
            $salesOrderId = (int) $marketplaceOrder->getSalesOrderId();

            if (isset($salesShipmentTracks[$salesOrderId]) && !empty($salesShipmentTracks[$salesOrderId])) {
                /** @var ShipmentTrack $chosenTrack */
                $chosenTrack = null;

                /** @var ShipmentTrack $shipmentTrack */
                foreach ($salesShipmentTracks[$salesOrderId] as $shipmentTrack) {
                    if ((null === $chosenTrack) || ($shipmentTrack->getRelevance() >= $chosenTrack->getRelevance())) {
                        $chosenTrack = $shipmentTrack;
                    }
                }

                $this->notifyStoreOrderShipment($marketplaceOrder, $chosenTrack, $store);
            }
        }
    }

    /**
     * @param StoreInterface $store
     * @throws \Exception
     */
    public function notifyStoreOrderUpdates(StoreInterface $store)
    {
        $this->notifyStoreOrderImportUpdates($store);
        $this->notifyStoreOrderCancellationUpdates($store);
        $this->notifyStoreOrderShipmentUpdates($store);
    }

    /**
     * @param OrderInterface $order
     * @param string $type
     * @param string $message
     * @param string $details
     * @throws CouldNotSaveException
     */
    public function logOrderMessage(OrderInterface $order, $type, $message, $details = '')
    {
        $log = $this->orderLogFactory->create();
        $log->setOrderId($order->getId());
        $log->setType($type);
        $log->setMessage($message);
        $log->setDetails($details);
        $this->orderLogRepository->save($log);
    }

    /**
     * @param OrderInterface $order
     * @param string $message
     * @param string $details
     * @throws CouldNotSaveException
     */
    public function logOrderDebug(OrderInterface $order, $message, $details = '')
    {
        $this->logOrderMessage($order, LogInterface::TYPE_DEBUG, $message, $details);
    }

    /**
     * @param OrderInterface $order
     * @param string $message
     * @param string $details
     * @throws CouldNotSaveException
     */
    public function logOrderInfo(OrderInterface $order, $message, $details = '')
    {
        $this->logOrderMessage($order, LogInterface::TYPE_INFO, $message, $details);
    }

    /**
     * @param OrderInterface $order
     * @param string $message
     * @param string $details
     * @throws CouldNotSaveException
     */
    public function logOrderError(OrderInterface $order, $message, $details = '')
    {
        $this->logOrderMessage($order, LogInterface::TYPE_ERROR, $message, $details);
    }
}

