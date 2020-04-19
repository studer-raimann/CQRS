<?php
/* Copyright (c) 2019 Extended GPL, see docs/LICENSE */

namespace srag\CQRS\Event;

use srag\CQRS\Aggregate\DomainObjectId;
use srag\CQRS\Exception\CQRSException;
use ilDateTime;

/**
 * Abstract Class EventStore
 *
 * @author  studer + raimann ag - Team Custom 1 <support-custom1@studer-raimann.ch>
 * @author  Adrian Lüthi <al@studer-raimann.ch>
 * @author  Björn Heyser <bh@bjoernheyser.de>
 * @author  Martin Studer <ms@studer-raimann.ch>
 * @author  Theodor Truffer <tt@studer-raimann.ch>
 */
abstract class EventStore {

    /**
     * @param DomainEvents $events
     *
     * @return void
     */
    public function commit(DomainEvents $events) : void {
        /** @var AbstractDomainEvent $event */
        foreach ($events->getEvents() as $event) {
            $ar_class = $this->getEventArClass();
            $stored_event = new $ar_class();
            
            $stored_event->setEventData(
                new EventID(),
                $event::getEventVersion(),
                $event->getAggregateId(),
                $event->getEventName(),
                $event->getOccurredOn(),
                $event->getInitiatingUserId(),
                $event->getEventBody(),
                get_class($event));
            
            $stored_event->create();
        }
    }
    
    /**
     * @param DomainObjectId $id
     *
     * @return DomainEvents
     */
    public function getAggregateHistoryFor(DomainObjectId $id): DomainEvents {
        global $DIC;

        $sql = sprintf(
            'SELECT * FROM %s where aggregate_id = %s',
            $this->getStorageName(),
            $DIC->database()->quote($id->getId(),'string')
        );
        
        $res = $DIC->database()->query($sql);
        
        if ($res->rowCount() === 0) {
            throw new CQRSException('Aggregate does not exist');
        }
        
        $event_stream = new DomainEvents();
        while ($row = $DIC->database()->fetchAssoc($res)) {
            /**@var AbstractDomainEvent $event */
            $event_name = $row['event_class'];
            $event = $event_name::restore(
                new EventID($row['event_id']),
                intval($row['event_version']),
                new DomainObjectId($row['aggregate_id']),
                intval($row['initiating_user_id']),
                new ilDateTime($row['occurred_on']),
                $row['event_body']);
            $event_stream->addEvent($event);
        }
        
        return $event_stream;
    }

    /**
     * @param EventID $from_position
     *
     * @return DomainEvents
     */
    public function getEventStream(?EventID $from_position) : DomainEvents {
        global $DIC;
        
        $sql = sprintf('SELECT * FROM %s', $this->getStorageName());
        
        if (!is_null($from_position)) {
            $sql .= sprintf(
                ' WHERE id > (SELECT id FROM %s WHERE event_id = "%s")',
                $this->getStorageName(),
                $from_position->getId()
            );
        }
        
        $res = $DIC->database()->query($sql);
        
        $event_stream = new DomainEvents();
        while ($row = $DIC->database()->fetchAssoc($res)) {
            /**@var AbstractDomainEvent $event */
            $event_name = $row['event_class'];
            $event = $event_name::restore(
                new EventID($row['event_id']),
                intval($row['event_version']),
                new DomainObjectId($row['aggregate_id']),
                intval($row['initiating_user_id']),
                new ilDateTime($row['occurred_on']),
                $row['event_body']);
            $event_stream->addEvent($event);
        }
        
        return $event_stream;
    }
    
    /**
     * @return string
     */
    protected function getStorageName() : string {
        return call_user_func($this->getEventArClass() . '::returnDbTableName');
    }
    
    /**
     * Gets the Active Record class that is used for the event store
     * 
     * @return string
     */
    protected abstract function getEventArClass() : string;
}