<?php

namespace Biigle\Modules\Ptp\Notifications;

use Biigle\Volume;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PtpJobConcluded extends Notification
{
    /**
     * The volume name for which the PTP job was running.
     *
     * @var string
     */
    protected string $volumeName;

    /**
     * The volume ID for which the PTP job was running.
     *
     * @var string
     */
    protected int $volumeId;
    /**
     * Create a new notification instance.
     *
     * @param string $volumeName
     * @return void
     */
    public function __construct(Volume $volume)
    {
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
        $message = (new MailMessage)
            ->subject('Your Point To Polygon conversion Job has concluded succesfully')
            ->line("The Point To Polygon conversion for volume $this->volumeName has concluded successfully.");

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
        $array = [
            'title' => 'Your Point To Polygon conversion Job has concluded succesfully',
            'message' => "The Point To Polygon conversion for volume $this->volumeName has concluded successfully.",
        ];

        return $array;
    }
}
