<?php

namespace Biigle\Modules\Ptp\Notifications;

use Biigle\Volume;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PtpJobConcluded extends Notification
{
    /**
     * The volume name for which the PTP job was running.
     */
    protected string $volumeName;

    /**
     * The volume ID for which the PTP job was running.
     */
    protected int $volumeId;

    /**
     * Create a new notification instance.
     *
     * @param $volume In which volume PTP was run
     * @param $convertedAnnotations If false, it signifies that no annotations were converted
     *
     * @return void
     */
    public function __construct(Volume $volume, public bool $convertedAnnotations)
    {
        echo($convertedAnnotations);
        $this->volumeName = $volume->name;
        $this->volumeId = $volume->id;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        $settings = config('ptp.notifications.default_settings');

        if (config('ptp.notifications.allow_user_settings') === true) {
            $settings = $notifiable->getSettings('ptp_notifications', $settings);
        }

        if ($settings === 'web') {
            return ['database'];
        }

        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $line = "The Magic SAM point conversion for volume $this->volumeName has concluded successfully.";

        if (!$this->convertedAnnotations) {
            $line = $line." However, no annotations were converted.";
        }

        $message = (new MailMessage)
            ->subject('Magic SAM point conversion finished')
            ->line($line);


        if (config('app.url')) {
            $message = $message->action('Show volume', route('volume', $this->volumeId));
        }

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        $message = "The Magic SAM point conversion for volume $this->volumeName has concluded successfully.";

        if (!$this->convertedAnnotations) {
            $message = $message." However, no annotations were converted.";
        }

        $array = [
            'title' => 'Magic SAM point conversion finished',
            'message' => $message,
            'action' => 'Show volume',
            'actionLink' => route('volume', $this->volumeId),
        ];


        return $array;
    }
}
