/* eslint-disable lingui/no-unlocalized-strings */
import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { t } from '@lingui/macro';
import { publicApi } from '../../../api/public-client';

const PaymentCallback = () => {
	const [params, setParams] = useState<{ eventId: string | null; orderId: string | null; reference: string | null }>({
		eventId: null,
		orderId: null,
		reference: null
	});

	useEffect(() => {
		const searchParams = new URLSearchParams(window.location.search);
		setParams({
			eventId: searchParams.get('event_id'),
			orderId: searchParams.get('order_id'),
			reference: searchParams.get('reference')
		});
	}, []);

	const { data, isLoading, error } = useQuery({
		queryKey: ['verifyPayment', params.reference],
		queryFn: async () => {
			if (!params.reference || !params.eventId || !params.orderId) {
				window.location.href = '/';
				return;
			}
			console.log('Verifying payment...', params);
			const response = await publicApi.get(`events/${params.eventId}/order/${params.orderId}/paystack/callback?reference=${params.reference}`);
			console.log('Payment verification response:', response.data);
			return response.data;
		},
		enabled: !!params.reference && !!params.eventId && !!params.orderId,
	});

	useEffect(() => {
		if (error) {
			console.log('Payment verification failed:', error);
			window.location.href = `/checkout/${params.eventId}/${params.orderId}/summary`;
		} else if (data) {
			console.log('Payment verification successful:', data);
			window.location.href = `/checkout/${params.eventId}/${params.orderId}/summary`;
		}
	}, [data, error, params.eventId, params.orderId]);

	return (
		<div className="flex min-h-screen items-center justify-center">
			<div className="text-center">
				<h1 className="text-2xl font-semibold mb-4">{t`Processing Payment`}</h1>
				<p className="text-gray-600">{t`Please wait while we verify your payment...`}</p>
			</div>
		</div>
	);
};

export default PaymentCallback;