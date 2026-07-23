import asyncio
import httpx
import time

BASE_URL = "http://localhost:8000"  # Candidate's API port
ITEM_ID = 1
TOTAL_CONCURRENT_USERS = 50

async def buy_item(client, user_id):
    try:
        response = await client.post(
            f"{BASE_URL}/items/{ITEM_ID}/buy",
            json={"user_id": f"user_{user_id}"}
        )
        return response.status_code, response.json()
    except Exception as e:
        return 500, str(e)

async def main():
    print(f" Simulating {TOTAL_CONCURRENT_USERS} concurrent purchase requests...")
    start_time = time.time()
    
    async with httpx.AsyncClient(timeout=10.0) as client:
        tasks = [buy_item(client, i) for i in range(1, TOTAL_CONCURRENT_USERS + 1)]
        results = await asyncio.gather(*tasks)

    duration = time.time() - start_time
    successes = [r for r in results if r[0] in (200, 201)]
    failures = [r for r in results if r[0] not in (200, 201)]

    print("\n--- RESULTS ---")
    print(f"Total Requests Processed: {len(results)}")
    print(f"Successful Purchases:     {len(successes)}")
    print(f"Failed/Rejected Requests: {len(failures)}")
    print(f"Time Taken:              {duration:.2f} seconds")
    print("\nCheck database state to confirm available_stock is exactly 0 and total orders = 10!")

if __name__ == "__main__":
    asyncio.run(main())
