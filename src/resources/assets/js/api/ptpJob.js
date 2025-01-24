
export default Vue.resource('api/v1/send-ptp-job', {}, {
    sendPtpJob: {
        method: 'POST',
        url: 'api/v1/send-ptp-job',
    }})
