import { useParams } from "react-router";
import { useEffect, useState } from "react";
import { useGetEventPublic } from "../../../../../../queries/useGetEventPublic";
import { CheckoutContent } from "../../../../../layouts/Checkout/CheckoutContent";
import { HomepageInfoMessage } from "../../../../../common/HomepageInfoMessage";
import { t } from "@lingui/macro";
import { eventHomepagePath } from "../../../../../../utilites/urlHelper";
import { LoadingMask } from "../../../../../common/LoadingMask";
import { Event } from "../../../../../../types";
import { useCreatePaystackTransaction } from "../../../../../../queries/useCreatePaystackTransaction";
import { Button, Text } from "@mantine/core";
import { IconPaywall } from "@tabler/icons-react";

interface PaystackPaymentMethodProps {
	enabled: boolean;
	setSubmitHandler: (submitHandler: () => () => Promise<void>) => void;
}

export const PaystackPaymentMethod = ({ enabled, setSubmitHandler }: PaystackPaymentMethodProps) => {
	const { eventId, orderShortId } = useParams();
	const {
		data: paystackData,
		isFetched: isPaystackFetched,
		error: paystackError
	} = useCreatePaystackTransaction(eventId, orderShortId);
	const { data: event } = useGetEventPublic(eventId);

	useEffect(() => {
		if (paystackData?.authorizationUrl) {
			setSubmitHandler(() => async () => {
				window.location.href = paystackData.authorizationUrl;
			});
		}
	}, [paystackData, setSubmitHandler]);

	if (!enabled) {
		return (
			<CheckoutContent>
				<HomepageInfoMessage
					message={t`Paystack payments are not enabled for this event.`}
					link={eventHomepagePath(event as Event)}
					linkText={t`Return to event page`}
				/>
			</CheckoutContent>
		);
	}

	if (paystackError && event) {
		return (
			<CheckoutContent>
				<HomepageInfoMessage
					message={paystackError.message || t`Sorry, something has gone wrong. Please restart the checkout process.`}
					link={eventHomepagePath(event)}
					linkText={t`Return to event page`}
				/>
			</CheckoutContent>
		);
	}

	if (!isPaystackFetched) {
		return <LoadingMask />;
	}

	return (
		<CheckoutContent>
			<div style={{ textAlign: 'center' }}>
				<Text size="sm" c="dimmed" mb="lg">
					{t`You will be redirected to Paystack to complete your payment securely.`}
				</Text>
				<Button
					variant="light"
					size="lg"
					leftSection={<IconPaywall size={24} />}
					onClick={() => window.location.href = paystackData?.authorizationUrl || ''}
				>
					{t`Pay with Paystack`}
				</Button>
			</div>
		</CheckoutContent>
	);
}; 