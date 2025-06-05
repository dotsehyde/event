import { useQuery } from "@tanstack/react-query";
import { publicApi } from "../api/public-client.ts";
import { IdParam } from "../types.ts";

interface PaystackTransactionResponse {
	reference: string;
	accessCode: string;
	authorizationUrl: string;
	accountId?: string;
	applicationFeeAmount: number;
}

export const useCreatePaystackTransaction = (eventId?: IdParam, orderShortId?: IdParam) => {
	return useQuery<PaystackTransactionResponse>({
		queryKey: ['paystack-transaction', eventId, orderShortId],
		queryFn: async () => {
			const response = await publicApi.post(`events/${eventId}/order/${orderShortId}/paystack-transaction`);
			// eslint-disable-next-line lingui/no-unlocalized-strings
			console.log("🚀 ~ queryFn: ~ response:", response)
			return response.data;
		},
		enabled: !!eventId && !!orderShortId,
	});


}; 